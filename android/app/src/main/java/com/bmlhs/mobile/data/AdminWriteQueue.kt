package com.bmlhs.mobile.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.util.concurrent.atomic.AtomicBoolean
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

data class QueuedAdminWrite(
    val id: String,
    val ownerId: Int,
    val endpoint: String,
    val encryptedPayload: String,
    val state: String,
    val lastError: String,
    val createdAtMillis: Long,
)

class AdminWriteQueue(context: Context) : SQLiteOpenHelper(context, "bmlhs_admin_queue.db", null, 1) {
    private val keyAlias = "bmlhs_mobile_admin_queue_key"
    private val mutableRevision = MutableStateFlow(0L)
    val revision = mutableRevision.asStateFlow()
    private val interruptedWritesRecovered = AtomicBoolean(false)

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            "CREATE TABLE admin_write_queue (" +
                "id TEXT PRIMARY KEY NOT NULL, " +
                "owner_id INTEGER NOT NULL, " +
                "endpoint TEXT NOT NULL, " +
                "encrypted_payload TEXT NOT NULL, " +
                "state TEXT NOT NULL, " +
                "last_error TEXT NOT NULL DEFAULT '', " +
                "created_at INTEGER NOT NULL)",
        )
        db.execSQL("CREATE INDEX idx_admin_queue_owner ON admin_write_queue(owner_id, created_at)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) = Unit

    fun enqueue(id: String, ownerId: Int, endpoint: String, payload: String) {
        require(ownerId > 0) { "A valid staff account is required to queue an Admin write." }
        val encrypted = encrypt(id, ownerId, endpoint, payload)
        val values = ContentValues().apply {
            put("id", id)
            put("owner_id", ownerId)
            put("endpoint", endpoint)
            put("encrypted_payload", encrypted)
            put("state", "pending")
            put("created_at", System.currentTimeMillis())
        }
        writableDatabase.insertOrThrow("admin_write_queue", null, values)
        mutableRevision.value += 1
    }

    fun list(ownerId: Int): List<QueuedAdminWrite> {
        require(ownerId > 0) { "A valid staff account is required to read queued Admin writes." }
        if (interruptedWritesRecovered.compareAndSet(false, true)) {
            writableDatabase.execSQL(
                "UPDATE admin_write_queue SET state = 'needs_confirmation', " +
                    "last_error = 'The previous attempt ended before its result was saved; verify before retrying.' " +
                    "WHERE state = 'sending'",
            )
        }
        val entries = mutableListOf<QueuedAdminWrite>()
        readableDatabase.rawQuery(
            "SELECT id, owner_id, endpoint, encrypted_payload, state, last_error, created_at " +
                "FROM admin_write_queue WHERE owner_id = ? ORDER BY created_at ASC",
            arrayOf(ownerId.toString()),
        ).use { cursor ->
            while (cursor.moveToNext()) {
                entries += QueuedAdminWrite(
                    id = cursor.getString(0),
                    ownerId = cursor.getInt(1),
                    endpoint = cursor.getString(2),
                    encryptedPayload = cursor.getString(3),
                    state = cursor.getString(4),
                    lastError = cursor.getString(5),
                    createdAtMillis = cursor.getLong(6),
                )
            }
        }
        return entries
    }

    fun readPayload(write: QueuedAdminWrite): String =
        decrypt(write.id, write.ownerId, write.endpoint, write.encryptedPayload)

    fun findExisting(ownerId: Int, endpoint: String, payload: String): QueuedAdminWrite? =
        list(ownerId).firstOrNull { write ->
            write.endpoint == endpoint && readPayload(write) == payload
        }

    fun mark(writeId: String, ownerId: Int, state: String, error: String = "") {
        require(state in setOf("pending", "sending", "needs_confirmation")) {
            "The queued Admin write state is invalid."
        }
        val values = ContentValues().apply {
            put("state", state)
            put("last_error", error.take(500))
        }
        val updated = writableDatabase.update(
            "admin_write_queue",
            values,
            "id = ? AND owner_id = ?",
            arrayOf(writeId, ownerId.toString()),
        )
        check(updated == 1) { "The queued Admin write could not be updated." }
        mutableRevision.value += 1
    }

    fun remove(writeId: String, ownerId: Int) {
        val deleted = writableDatabase.delete(
            "admin_write_queue",
            "id = ? AND owner_id = ?",
            arrayOf(writeId, ownerId.toString()),
        )
        check(deleted == 1) { "The queued Admin write could not be removed." }
        mutableRevision.value += 1
    }

    fun retryWithNewKey(writeId: String, ownerId: Int): String {
        val database = writableDatabase
        database.beginTransaction()
        try {
            val oldWrite = database.rawQuery(
                "SELECT endpoint, encrypted_payload FROM admin_write_queue WHERE id = ? AND owner_id = ?",
                arrayOf(writeId, ownerId.toString()),
            ).use { cursor ->
                check(cursor.moveToFirst()) { "The queued Admin write no longer exists." }
                cursor.getString(0) to cursor.getString(1)
            }
            val payload = decrypt(writeId, ownerId, oldWrite.first, oldWrite.second)
            val newId = UUID.randomUUID().toString()
            val values = ContentValues().apply {
                put("id", newId)
                put("owner_id", ownerId)
                put("endpoint", oldWrite.first)
                put("encrypted_payload", encrypt(newId, ownerId, oldWrite.first, payload))
                put("state", "pending")
                put("created_at", System.currentTimeMillis())
            }
            database.insertOrThrow("admin_write_queue", null, values)
            val removed = database.delete(
                "admin_write_queue",
                "id = ? AND owner_id = ?",
                arrayOf(writeId, ownerId.toString()),
            )
            check(removed == 1) { "The previous queued Admin write could not be replaced." }
            database.setTransactionSuccessful()
            mutableRevision.value += 1
            return newId
        } finally {
            database.endTransaction()
        }
    }

    private fun encrypt(id: String, ownerId: Int, endpoint: String, value: String): String {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        cipher.updateAAD("$ownerId:$id:$endpoint".toByteArray(Charsets.UTF_8))
        val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
        return Base64.encodeToString(cipher.iv + encrypted, Base64.NO_WRAP)
    }

    private fun decrypt(id: String, ownerId: Int, endpoint: String, encoded: String): String {
        val input = Base64.decode(encoded, Base64.NO_WRAP)
        require(input.size > 12) { "Queued Admin write data is invalid." }
        val iv = input.copyOfRange(0, 12)
        val ciphertext = input.copyOfRange(12, input.size)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, iv))
        cipher.updateAAD("$ownerId:$id:$endpoint".toByteArray(Charsets.UTF_8))
        return String(cipher.doFinal(ciphertext), Charsets.UTF_8)
    }

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getKey(keyAlias, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                keyAlias,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build(),
        )
        return generator.generateKey()
    }
}
