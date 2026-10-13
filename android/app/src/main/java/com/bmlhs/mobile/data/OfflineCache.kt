package com.bmlhs.mobile.data

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

class OfflineCache(context: Context) : SQLiteOpenHelper(context, "bmlhs_offline.db", null, 1) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            "CREATE TABLE cached_payloads (" +
                "cache_key TEXT PRIMARY KEY NOT NULL, " +
                "payload TEXT NOT NULL, " +
                "updated_at INTEGER NOT NULL)",
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 1) onCreate(db)
    }

    fun read(key: String): CachedValue? {
        readableDatabase.rawQuery(
            "SELECT payload, updated_at FROM cached_payloads WHERE cache_key = ?",
            arrayOf(key),
        ).use { cursor ->
            if (!cursor.moveToFirst()) return null
            return CachedValue(cursor.getString(0), cursor.getLong(1))
        }
    }

    fun write(key: String, payload: String) {
        val values = android.content.ContentValues().apply {
            put("cache_key", key)
            put("payload", payload)
            put("updated_at", System.currentTimeMillis())
        }
        writableDatabase.insertWithOnConflict(
            "cached_payloads",
            null,
            values,
            SQLiteDatabase.CONFLICT_REPLACE,
        )
    }

    fun remove(key: String) {
        writableDatabase.delete("cached_payloads", "cache_key = ?", arrayOf(key))
    }

    fun clearPrivateData() {
        writableDatabase.delete("cached_payloads", "cache_key LIKE ?", arrayOf("private:%"))
    }
}

data class CachedValue(val payload: String, val updatedAtMillis: Long)
