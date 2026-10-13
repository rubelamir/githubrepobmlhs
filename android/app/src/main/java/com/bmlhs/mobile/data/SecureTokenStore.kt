package com.bmlhs.mobile.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.security.GeneralSecurityException
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class SecureTokenStore(context: Context) {
    private val preferences = context.getSharedPreferences("secure_session", Context.MODE_PRIVATE)
    private val keyAlias = "bmlhs_mobile_token_key"

    fun read(): String? {
        val iv = preferences.getString("iv", null)
        val encrypted = preferences.getString("token", null)
        if (iv == null && encrypted == null) return null
        if (iv == null || encrypted == null) {
            clear()
            throw IllegalStateException("সংরক্ষিত session অসম্পূর্ণ; আবার লগইন করুন।")
        }
        return try {
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
            String(cipher.doFinal(Base64.decode(encrypted, Base64.NO_WRAP)), Charsets.UTF_8)
        } catch (exception: GeneralSecurityException) {
            clear()
            throw IllegalStateException("সংরক্ষিত session পড়া যায়নি; আবার লগইন করুন।", exception)
        } catch (exception: IllegalArgumentException) {
            clear()
            throw IllegalStateException("সংরক্ষিত session পড়া যায়নি; আবার লগইন করুন।", exception)
        } catch (exception: ClassCastException) {
            clear()
            throw IllegalStateException("সংরক্ষিত session-এর format বৈধ নয়; আবার লগইন করুন।", exception)
        }
    }

    fun write(token: String) {
        writeSession(token, null)
    }

    fun writeSession(accessToken: String, refreshToken: String?) {
        val editor = preferences.edit()
        encryptValue(accessToken, "iv", "token", editor)
        if (refreshToken.isNullOrBlank()) {
            editor.remove("refresh_iv").remove("refresh_token")
        } else {
            encryptValue(refreshToken, "refresh_iv", "refresh_token", editor)
        }
        editor.apply()
    }

    fun readRefreshToken(): String? = readValue(
        ivKey = "refresh_iv",
        valueKey = "refresh_token",
        label = "সংরক্ষিত refresh session",
    )

    private fun readValue(ivKey: String, valueKey: String, label: String): String? {
        val iv = preferences.getString(ivKey, null)
        val encrypted = preferences.getString(valueKey, null)
        if (iv == null && encrypted == null) return null
        if (iv == null || encrypted == null) {
            clear()
            throw IllegalStateException("$label অসম্পূর্ণ; আবার লগইন করুন।")
        }
        return try {
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
            String(cipher.doFinal(Base64.decode(encrypted, Base64.NO_WRAP)), Charsets.UTF_8)
        } catch (exception: GeneralSecurityException) {
            clear()
            throw IllegalStateException("$label পড়া যায়নি; আবার লগইন করুন।", exception)
        } catch (exception: IllegalArgumentException) {
            clear()
            throw IllegalStateException("$label পড়া যায়নি; আবার লগইন করুন।", exception)
        } catch (exception: ClassCastException) {
            clear()
            throw IllegalStateException("$label-এর format বৈধ নয়; আবার লগইন করুন।", exception)
        }
    }

    private fun encryptValue(
        value: String,
        ivKey: String,
        valueKey: String,
        editor: android.content.SharedPreferences.Editor,
    ) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())
        editor
            .putString(ivKey, Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putString(
                valueKey,
                Base64.encodeToString(cipher.doFinal(value.toByteArray(Charsets.UTF_8)), Base64.NO_WRAP),
            )
    }

    fun clear() {
        preferences.edit().clear().apply()
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
