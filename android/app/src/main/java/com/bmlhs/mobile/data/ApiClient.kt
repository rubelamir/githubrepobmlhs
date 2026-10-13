package com.bmlhs.mobile.data

import com.bmlhs.mobile.BuildConfig
import android.util.Base64
import android.database.sqlite.SQLiteException
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.BufferedReader
import java.io.ByteArrayOutputStream
import java.io.IOException
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.GeneralSecurityException
import java.util.UUID
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

data class AdminUploadFile(
    val fieldName: String,
    val fileName: String,
    val mimeType: String,
    val bytes: ByteArray,
)

private data class MultipartPayload(
    val fields: Map<String, String>,
    val files: List<AdminUploadFile>,
)

class ApiClient(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
    private val adminWriteQueue: AdminWriteQueue? = null,
) {
    private val refreshLock = Any()
    private val replacementTokens = mutableMapOf<String, String>()
    private val expiredSessionState = MutableStateFlow(false)
    val sessionExpired: StateFlow<Boolean> = expiredSessionState
    @Volatile
    private var tokenRefresher: ((String) -> String)? = null
    @Volatile
    private var adminQueueOwnerId = 0
    private val emptyQueueRevision = MutableStateFlow(0L)
    val adminQueueRevision: StateFlow<Long> = adminWriteQueue?.revision ?: emptyQueueRevision

    fun setAdminQueueOwner(ownerId: Int) {
        require(ownerId >= 0) { "The Admin queue owner is invalid." }
        adminQueueOwnerId = ownerId
    }

    fun setTokenRefresher(refresher: (String) -> String) {
        tokenRefresher = refresher
    }

    fun clearTokenReplacements() {
        synchronized(refreshLock) {
            replacementTokens.clear()
        }
    }

    fun clearExpiredSessionFlag() {
        expiredSessionState.value = false
    }

    fun login(accountType: String, mobile: String, password: String): JSONObject =
        request(
            path = "auth/login.php",
            method = "POST",
            body = JSONObject()
                .put("account_type", accountType)
                .put("mobile", mobile)
                .put("password", password)
                .put("device_name", android.os.Build.MODEL),
        )

    fun refresh(refreshToken: String): JSONObject =
        request(
            path = "auth/refresh.php",
            method = "POST",
            body = JSONObject().put("refresh_token", refreshToken),
        )

    fun me(token: String): JSONObject = request("auth/me.php", token = token)

    fun accountSummary(token: String, sessionYear: String): JSONObject =
        request(
            "account/summary.php?session_year=" + URLEncoder.encode(sessionYear, Charsets.UTF_8.name()),
            token = token,
        )

    fun teacherAttendance(
        token: String,
        classId: Int,
        sectionId: Int,
        sessionYear: String,
        date: String,
    ): JSONObject =
        request(
            "teacher/attendance.php?class_id=$classId&section_id=$sectionId&session_year=" +
                URLEncoder.encode(sessionYear, Charsets.UTF_8.name()) + "&date=" +
                URLEncoder.encode(date, Charsets.UTF_8.name()),
            token = token,
        )

    fun saveTeacherAttendance(token: String, payload: JSONObject): JSONObject =
        queuedJsonWrite(token, "teacher/attendance.php", payload)

    fun teacherMarks(
        token: String,
        classId: Int,
        sectionId: Int,
        sessionYear: String,
        examId: Int = 0,
        subjectId: Int = 0,
    ): JSONObject =
        request(
            "teacher/marks.php?class_id=$classId&section_id=$sectionId&session_year=" +
                URLEncoder.encode(sessionYear, Charsets.UTF_8.name()) +
                "&exam_id=$examId&subject_id=$subjectId",
            token = token,
        )

    fun saveTeacherMarks(token: String, payload: JSONObject): JSONObject =
        queuedJsonWrite(token, "teacher/marks.php", payload)

    fun adminNotices(token: String): JSONObject =
        request("admin/notices.php", token = token)

    fun manageAdminNotice(token: String, payload: JSONObject): JSONObject =
        adminRequest(token, "admin/notices.php", "POST", payload)

    fun adminCalendar(token: String): JSONObject =
        request("admin/calendar.php", token = token)

    fun manageAdminCalendar(token: String, payload: JSONObject): JSONObject =
        adminRequest(token, "admin/calendar.php", "POST", payload)

    fun adminHeadlines(token: String): JSONObject =
        request("admin/headlines.php", token = token)

    fun manageAdminHeadline(token: String, payload: JSONObject): JSONObject =
        adminRequest(token, "admin/headlines.php", "POST", payload)

    fun adminRequest(
        token: String,
        endpoint: String,
        method: String = "GET",
        payload: JSONObject? = null,
        query: Map<String, String> = emptyMap(),
    ): JSONObject {
        require(Regex("^admin/[a-z-]+\\.php$").matches(endpoint)) {
            "The requested Admin API endpoint is invalid."
        }
        require(method == "GET" || method == "POST") {
            "Only GET and POST are supported for Admin API requests."
        }
        require((method == "GET" && payload == null) || (method == "POST" && payload != null)) {
            "The request payload does not match the Admin API method."
        }
        require(query.keys.all { Regex("^[a-z_]+$").matches(it) }) {
            "The Admin API query parameters are invalid."
        }
        val queryString = query.entries.joinToString("&") { (key, value) ->
            URLEncoder.encode(key, Charsets.UTF_8.name()) + "=" +
                URLEncoder.encode(value, Charsets.UTF_8.name())
        }
        val path = if (queryString.isBlank()) endpoint else "$endpoint?$queryString"
        if (method == "GET") return request(path, token = token)
        return queuedJsonWrite(
            token,
            path,
            payload ?: throw IllegalArgumentException("A write payload is required."),
        )
    }

    private fun queuedJsonWrite(token: String, endpoint: String, payload: JSONObject): JSONObject {
        require(Regex("^(admin|teacher)/[a-z-]+\\.php(?:\\?.*)?$").matches(endpoint)) {
            "The requested staff API endpoint is invalid."
        }
        val queuedPayload = JSONObject()
            .put("type", "json")
            .put("body", payload.toString())
        existingQueuedWrite(endpoint, queuedPayload.toString())?.let { existing ->
            throw AdminWriteQueuedException(
                existing.id,
                IOException("The same staff write is already queued."),
                "একই পরিবর্তন ইতিমধ্যে queue-তে আছে; Dashboard-এর Offline পরিবর্তন অংশ থেকে পাঠান।",
            )
        }
        val idempotencyKey = UUID.randomUUID().toString()
        try {
            return request(
                endpoint,
                method = "POST",
                token = token,
                body = payload,
                idempotencyKey = idempotencyKey,
            )
        } catch (exception: ApiException) {
            if (exception.statusCode in 500..599) {
                queueAdminWrite(idempotencyKey, endpoint, queuedPayload, IOException(exception.message, exception))
            }
            throw exception
        } catch (exception: IOException) {
            queueAdminWrite(idempotencyKey, endpoint, queuedPayload, exception)
        } catch (exception: JSONException) {
            queueAdminWrite(
                idempotencyKey,
                endpoint,
                queuedPayload,
                IOException("The staff API response could not be parsed.", exception),
            )
        }
    }

    fun adminMultipartRequest(
        token: String,
        endpoint: String,
        fields: Map<String, String>,
        files: List<AdminUploadFile>,
    ): JSONObject {
        require(Regex("^admin/[a-z-]+\\.php$").matches(endpoint)) {
            "The requested Admin API endpoint is invalid."
        }
        require(fields.keys.all { Regex("^[a-z_]+$").matches(it) }) {
            "The Admin API form fields are invalid."
        }
        require(files.isNotEmpty()) { "At least one upload file is required." }
        val fieldPayload = JSONObject()
        fields.forEach { (name, value) -> fieldPayload.put(name, value) }
        val filePayload = JSONArray()
        files.forEach { file ->
            filePayload.put(
                JSONObject()
                    .put("field_name", file.fieldName)
                    .put("file_name", file.fileName)
                    .put("mime_type", file.mimeType)
                    .put("bytes", Base64.encodeToString(file.bytes, Base64.NO_WRAP)),
            )
        }
        val queuedPayload = JSONObject().put("type", "multipart").put("fields", fieldPayload).put("files", filePayload)
        existingQueuedWrite(endpoint, queuedPayload.toString())?.let { existing ->
            throw AdminWriteQueuedException(
                existing.id,
                IOException("The same Admin write is already queued."),
                "একই Admin পরিবর্তন ইতিমধ্যে queue-তে আছে; সেখান থেকে পাঠান বা বাদ দিন।",
            )
        }
        val idempotencyKey = UUID.randomUUID().toString()
        try {
            return request(
                endpoint,
                method = "POST",
                token = token,
                multipart = MultipartPayload(fields, files),
                idempotencyKey = idempotencyKey,
            )
        } catch (exception: ApiException) {
            if (exception.statusCode in 500..599) {
                queueAdminWrite(idempotencyKey, endpoint, queuedPayload, IOException(exception.message, exception))
            }
            throw exception
        } catch (exception: IOException) {
            queueAdminWrite(idempotencyKey, endpoint, queuedPayload, exception)
        } catch (exception: JSONException) {
            queueAdminWrite(idempotencyKey, endpoint, queuedPayload, IOException("The Admin response could not be parsed.", exception))
        }
    }

    fun queuedAdminWrites(ownerId: Int): List<QueuedAdminWrite> =
        adminWriteQueue?.list(ownerId).orEmpty()

    fun discardQueuedAdminWrite(ownerId: Int, writeId: String) {
        requireNotNull(adminWriteQueue) { "Offline Admin queue is unavailable." }.remove(writeId, ownerId)
    }

    fun syncQueuedAdminWrites(token: String, ownerId: Int): Int {
        val queue = requireNotNull(adminWriteQueue) { "Offline Admin queue is unavailable." }
        return syncQueuedWrites(token, ownerId, queue.list(ownerId))
    }

    fun forceRetryQueuedAdminWrite(token: String, ownerId: Int, writeId: String): Int {
        val queue = requireNotNull(adminWriteQueue) { "Offline Admin queue is unavailable." }
        val newId = queue.retryWithNewKey(writeId, ownerId)
        val write = queue.list(ownerId).firstOrNull { it.id == newId }
            ?: throw IllegalStateException("The replacement Admin write could not be found.")
        return syncQueuedWrites(token, ownerId, listOf(write))
    }

    private fun syncQueuedWrites(token: String, ownerId: Int, writes: List<QueuedAdminWrite>): Int {
        val queue = requireNotNull(adminWriteQueue) { "Offline Admin queue is unavailable." }
        var completed = 0
        for (write in writes) {
            queue.mark(write.id, ownerId, "sending")
            try {
                require(Regex("^(admin|teacher)/[a-z-]+\\.php(?:\\?.*)?$").matches(write.endpoint)) {
                    "The queued staff API endpoint is invalid."
                }
                val payload = JSONObject(queue.readPayload(write))
                when (payload.optString("type")) {
                    "json" -> request(
                        write.endpoint,
                        method = "POST",
                        token = token,
                        body = JSONObject(payload.getString("body")),
                        idempotencyKey = write.id,
                    )
                    "multipart" -> {
                        val fields = payload.getJSONObject("fields")
                        val fieldMap = mutableMapOf<String, String>()
                        val names = fields.keys()
                        while (names.hasNext()) {
                            val name = names.next()
                            fieldMap[name] = fields.getString(name)
                        }
                        val files = mutableListOf<AdminUploadFile>()
                        val fileArray = payload.getJSONArray("files")
                        for (index in 0 until fileArray.length()) {
                            val file = fileArray.getJSONObject(index)
                            files += AdminUploadFile(
                                file.getString("field_name"),
                                file.getString("file_name"),
                                file.getString("mime_type"),
                                Base64.decode(file.getString("bytes"), Base64.NO_WRAP),
                            )
                        }
                        request(
                            write.endpoint,
                            method = "POST",
                            token = token,
                            multipart = MultipartPayload(fieldMap, files),
                            idempotencyKey = write.id,
                        )
                    }
                    else -> throw IllegalArgumentException("The queued Admin write format is invalid.")
                }
                queue.remove(write.id, ownerId)
                completed++
            } catch (exception: ApiException) {
                queue.mark(write.id, ownerId, "needs_confirmation", exception.message)
                throw QueuedAdminSyncException(write.id, exception.statusCode, exception.message, exception)
            } catch (exception: IOException) {
                queue.mark(write.id, ownerId, "pending", exception.message ?: "Network unavailable.")
                throw QueuedAdminSyncException(
                    write.id,
                    0,
                    "ইন্টারনেট সংযোগ পাওয়া যায়নি; পরিবর্তন queue-তে আছে।",
                    exception,
                )
            } catch (exception: Exception) {
                queue.mark(write.id, ownerId, "needs_confirmation", exception.message ?: "The queued write needs review.")
                throw QueuedAdminSyncException(
                    write.id,
                    0,
                    "Queue-তে থাকা পরিবর্তনটি যাচাই করুন।",
                    exception,
                )
            }
        }
        return completed
    }

    private fun queueAdminWrite(
        id: String,
        endpoint: String,
        payload: JSONObject,
        cause: IOException,
    ): Nothing {
        val ownerId = adminQueueOwnerId
        val queue = adminWriteQueue
        if (ownerId < 1 || queue == null) throw cause
        val existing = try {
            queue.findExisting(ownerId, endpoint, payload.toString())
        } catch (exception: GeneralSecurityException) {
            throw ApiException(500, "Encrypted offline change queue পড়া যায়নি।", exception)
        } catch (exception: IllegalArgumentException) {
            throw ApiException(500, "Offline change queue-এর সংরক্ষিত তথ্য বৈধ নয়।", exception)
        } catch (exception: IllegalStateException) {
            throw ApiException(500, "Offline change queue পড়া যায়নি।", exception)
        } catch (exception: SQLiteException) {
            throw ApiException(500, "Offline change queue পড়া যায়নি।", exception)
        }
        existing?.let {
            throw AdminWriteQueuedException(
                it.id,
                cause,
                "একই পরিবর্তন ইতিমধ্যে queue-তে আছে; Dashboard-এর Offline পরিবর্তন অংশ থেকে পাঠান।",
            )
        }
        try {
            queue.enqueue(id, ownerId, endpoint, payload.toString())
        } catch (exception: GeneralSecurityException) {
            throw ApiException(500, "পরিবর্তনটি নিরাপদে offline queue-তে সংরক্ষণ করা যায়নি।", exception)
        } catch (exception: SQLiteException) {
            throw ApiException(500, "পরিবর্তনটি offline queue-তে সংরক্ষণ করা যায়নি।", exception)
        } catch (exception: IllegalStateException) {
            throw ApiException(500, "পরিবর্তনটি নিরাপদে offline queue-তে সংরক্ষণ করা যায়নি।", exception)
        }
        throw AdminWriteQueuedException(
            id,
            cause,
            "সার্ভারে পৌঁছানো যায়নি; পরিবর্তনটি ফোনে নিরাপদে রাখা হয়েছে। সংযোগ ফিরে এলে Dashboard-এর Offline পরিবর্তন অংশ থেকে পাঠান।",
        )
    }

    private fun existingQueuedWrite(endpoint: String, payload: String): QueuedAdminWrite? {
        val ownerId = adminQueueOwnerId
        if (ownerId < 1) return null
        return try {
            adminWriteQueue?.findExisting(ownerId, endpoint, payload)
        } catch (exception: GeneralSecurityException) {
            throw ApiException(500, "Encrypted offline change queue পড়া যায়নি।", exception)
        } catch (exception: IllegalArgumentException) {
            throw ApiException(500, "Offline Admin queue-এর সংরক্ষিত তথ্য বৈধ নয়।", exception)
        } catch (exception: IllegalStateException) {
            throw ApiException(500, "Offline Admin queue পড়া যায়নি।", exception)
        } catch (exception: SQLiteException) {
            throw ApiException(500, "Offline Admin queue পড়া যায়নি।", exception)
        }
    }

    fun publicHome(): JSONObject = request("public/home.php")

    fun publicCalendar(year: Int, month: Int): JSONObject =
        request("public/calendar.php?year=$year&month=$month")

    fun publicCatalog(classId: Int? = null): JSONObject =
        request("public/catalog.php" + (classId?.let { "?class_id=$it" } ?: ""))

    fun publicStaff(): JSONObject = request("public/staff.php")

    fun publicDownloads(): JSONObject = request("public/downloads.php")

    fun publicGallery(albumId: Int? = null): JSONObject =
        request("public/gallery.php" + (albumId?.let { "?album_id=$it" } ?: ""))

    fun publicRoutine(classId: Int, sectionId: Int = 0): JSONObject =
        request("public/routine.php?class_id=$classId&section_id=$sectionId")

    fun publicResults(classId: Int, examId: Int, year: String): JSONObject =
        request(
            "public/results.php?class_id=$classId&exam_id=$examId&session_year=" +
                URLEncoder.encode(year, Charsets.UTF_8.name()),
        )

    fun logout(token: String): JSONObject = request("auth/logout.php", method = "POST", token = token)

    private fun request(
        path: String,
        method: String = "GET",
        token: String? = null,
        body: JSONObject? = null,
        multipart: MultipartPayload? = null,
        idempotencyKey: String? = null,
        retryUnauthorized: Boolean = true,
    ): JSONObject {
        val endpoint = URL(baseUrl.trimEnd('/') + "/" + path.trimStart('/'))
        val localDevelopmentHost = endpoint.host == "localhost" || endpoint.host == "127.0.0.1"
        require(endpoint.protocol == "https" || (BuildConfig.DEBUG && endpoint.protocol == "http" && localDevelopmentHost)) {
            "The mobile API must use HTTPS."
        }
        val connection = endpoint.openConnection() as HttpURLConnection
        connection.requestMethod = method
        connection.connectTimeout = 12_000
        connection.readTimeout = 20_000
        connection.setRequestProperty("Accept", "application/json")
        val requestToken = token?.takeIf(String::isNotBlank)?.let { originalToken ->
            synchronized(refreshLock) { replacementTokens[originalToken] } ?: originalToken
        }
        if (!idempotencyKey.isNullOrBlank()) {
            connection.setRequestProperty("Idempotency-Key", idempotencyKey)
        }
        if (!requestToken.isNullOrBlank()) {
            connection.setRequestProperty("Authorization", "Bearer $requestToken")
        }
        require(body == null || multipart == null) {
            "JSON and multipart bodies cannot be sent together."
        }
        try {
            if (body != null) {
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            } else if (multipart != null) {
                writeMultipart(connection, multipart)
            }

            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val response = stream?.use { input ->
                BufferedReader(InputStreamReader(input, Charsets.UTF_8)).readText()
            }.orEmpty()
            if (status == 401 && retryUnauthorized && !requestToken.isNullOrBlank()) {
                connection.disconnect()
                val refresher = tokenRefresher
                    ?: run {
                        expiredSessionState.value = true
                        throw ApiException(401, "সেশন মেয়াদোত্তীর্ণ; আবার লগইন করুন।")
                    }
                val freshToken = try {
                    synchronized(refreshLock) {
                        val replacementForOriginal = token?.let(replacementTokens::get)
                        if (!replacementForOriginal.isNullOrBlank() && replacementForOriginal != requestToken) {
                            replacementForOriginal
                        } else {
                            refresher(requestToken).also { refreshed ->
                                replacementTokens[requestToken] = refreshed
                                token?.let { replacementTokens[it] = refreshed }
                            }
                        }
                    }
                } catch (exception: ApiException) {
                    if (exception.statusCode == 401) expiredSessionState.value = true
                    throw exception
                }
                return request(
                    path = path,
                    method = method,
                    token = freshToken,
                    body = body,
                    multipart = multipart,
                    idempotencyKey = idempotencyKey,
                    retryUnauthorized = false,
                )
            }
            if (status == 401 && !requestToken.isNullOrBlank()) {
                expiredSessionState.value = true
            }
            val json = if (response.isBlank()) JSONObject() else JSONObject(response)
            if (status !in 200..299) {
                val error = json.optJSONObject("error")
                throw ApiException(
                    status,
                    error?.optString("message")?.takeIf(String::isNotBlank) ?: "সার্ভার অনুরোধটি গ্রহণ করেনি।",
                )
            }
            return json
        } finally {
            connection.disconnect()
        }
    }

    private fun writeMultipart(connection: HttpURLConnection, multipart: MultipartPayload) {
        val boundary = "Bmlhs-${UUID.randomUUID()}"
        val body = ByteArrayOutputStream()
        body.use { output ->
            fun writeText(value: String) = output.write(value.toByteArray(Charsets.UTF_8))
            multipart.fields.forEach { (name, value) ->
                writeText("--$boundary\r\n")
                writeText("Content-Disposition: form-data; name=\"$name\"\r\n\r\n")
                writeText(value)
                writeText("\r\n")
            }
            multipart.files.forEach { file ->
                require(Regex("^[a-z_\\[\\]]+$").matches(file.fieldName)) {
                    "The upload field name is invalid."
                }
                val safeFileName = file.fileName
                    .substringAfterLast('/')
                    .substringAfterLast('\\')
                    .replace("\"", "")
                    .replace("\r", "")
                    .replace("\n", "")
                require(safeFileName.isNotBlank() && file.mimeType.matches(Regex("^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$"))) {
                    "The upload file metadata is invalid."
                }
                writeText("--$boundary\r\n")
                writeText("Content-Disposition: form-data; name=\"${file.fieldName}\"; filename=\"$safeFileName\"\r\n")
                writeText("Content-Type: ${file.mimeType}\r\n\r\n")
                output.write(file.bytes)
                writeText("\r\n")
            }
            writeText("--$boundary--\r\n")
        }
        connection.doOutput = true
        connection.setFixedLengthStreamingMode(body.size().toLong())
        connection.setRequestProperty("Content-Type", "multipart/form-data; boundary=$boundary")
        connection.outputStream.use { it.write(body.toByteArray()) }
    }
}

open class ApiException(
    val statusCode: Int,
    override val message: String,
    cause: Throwable? = null,
) : Exception(message, cause)

class AdminWriteQueuedException(
    val writeId: String,
    cause: IOException,
    message: String = "ইন্টারনেট সংযোগ না থাকায় Admin পরিবর্তনটি নিরাপদ queue-তে রাখা হয়েছে।",
) : ApiException(
    202,
    message,
    cause,
)

class QueuedAdminSyncException(
    val writeId: String,
    val statusCode: Int,
    override val message: String,
    cause: Exception,
) : Exception(message, cause)
