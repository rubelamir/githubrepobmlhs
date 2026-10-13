package com.bmlhs.mobile

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import com.bmlhs.mobile.data.ApiException
import com.bmlhs.mobile.data.AdminUploadFile
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException

private data class AdminNotice(
    val id: Int,
    val text: String,
    val createdAt: String,
    val isLive: Boolean,
    val attachmentUrl: String?,
)

@Composable
internal fun AdminNotices(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var notices by remember { mutableStateOf<List<AdminNotice>>(emptyList()) }
    var noticeText by remember { mutableStateOf("") }
    var selectedFile by remember { mutableStateOf<PickedAdminFile?>(null) }
    var processingFile by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var deleteNotice by remember { mutableStateOf<AdminNotice?>(null) }
    val filePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            selectedFile = null
            processingFile = true
            scope.launch {
                try {
                    selectedFile = withContext(Dispatchers.IO) { readAdminUpload(context, uri) }
                    error = ""
                } catch (exception: IOException) {
                    error = "নির্বাচিত ফাইলটি পড়া যায়নি।"
                } catch (exception: IllegalArgumentException) {
                    error = exception.message ?: "সমর্থিত ফাইল বাছুন।"
                } finally {
                    processingFile = false
                }
            }
        }
    }

    suspend fun reload() {
        val response = withContext(Dispatchers.IO) { api.adminNotices(token) }
        notices = parseAdminNotices(response.optJSONObject("data")?.optJSONArray("notices") ?: JSONArray())
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
            error = ""
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "নোটিশ সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "নোটিশের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    fun perform(action: String, noticeId: Int? = null, text: String = "") {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action)
                noticeId?.let { payload.put("id", it) }
                if (action == "publish") payload.put("text", text)
                if (action == "publish" && selectedFile != null) {
                    val file = selectedFile!!
                    withContext(Dispatchers.IO) {
                        api.adminMultipartRequest(
                            token,
                            "admin/notices.php",
                            fields = mapOf("action" to action, "text" to text),
                            files = listOf(AdminUploadFile("main_notice_file", file.name, file.mimeType, file.bytes)),
                        )
                    }
                } else {
                    withContext(Dispatchers.IO) { api.manageAdminNotice(token, payload) }
                }
                if (action == "publish") {
                    noticeText = ""
                    selectedFile = null
                }
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "নোটিশ সংরক্ষণে সার্ভার সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "নোটিশের সার্ভার উত্তর পড়া যায়নি।"
            } catch (exception: IllegalArgumentException) {
                error = exception.message ?: "নোটিশ সংরক্ষণ করা যায়নি।"
            } catch (exception: CancellationException) {
                throw exception
            } finally {
                busy = false
            }
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("নোটিশ ব্যবস্থাপনা", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("নোটিশ ওয়েবসাইটে প্রকাশিত হবে। অ্যাপে Public home পুনরায় লোড করলে নতুন নোটিশ দেখা যাবে।")
            OutlinedTextField(
                value = noticeText,
                onValueChange = { noticeText = it.take(10_000) },
                label = { Text("নোটিশের লেখা") },
                minLines = 3,
                maxLines = 8,
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(
                    enabled = !busy && !processingFile,
                    onClick = { filePicker.launch("*/*") },
                ) {
                    Text(if (processingFile) "ফাইল পড়া হচ্ছে…" else if (selectedFile == null) "Attachment বাছুন" else "অন্য ফাইল বাছুন")
                }
                selectedFile?.let { Text(it.name, modifier = Modifier.padding(top = 12.dp)) }
            }
            Text("ঐচ্ছিক সংযুক্তি: PDF, Word, Excel, JPG বা PNG · সর্বোচ্চ ৫ MB")
            Button(
                enabled = !busy && !processingFile && noticeText.isNotBlank(),
                onClick = { perform("publish", text = noticeText.trim()) },
                modifier = Modifier.fillMaxWidth(),
            ) { Text(if (busy) "অপেক্ষা করুন…" else "নোটিশ প্রকাশ করুন") }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("সাম্প্রতিক ১০০টি নোটিশ", fontWeight = FontWeight.SemiBold)
                OutlinedButton(enabled = !busy, onClick = {
                    busy = true
                    error = ""
                    scope.launch {
                        try {
                            reload()
                        } catch (exception: ApiException) {
                            error = exception.message
                        } catch (exception: IOException) {
                            error = "নোটিশ সার্ভারে সংযোগ করা যায়নি।"
                        } catch (exception: JSONException) {
                            error = "নোটিশের সার্ভার উত্তর পড়া যায়নি।"
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Refresh") }
            }
            if (notices.isEmpty() && !busy) {
                Text("কোনো নোটিশ নেই।", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            notices.forEach { notice ->
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(notice.text, style = MaterialTheme.typography.bodyLarge)
                        Text(
                            "${if (notice.isLive) "প্রকাশিত" else "লুকানো"} · ${notice.createdAt}",
                            color = if (notice.isLive) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        notice.attachmentUrl?.let {
                            Text("সংযুক্তি: $it", style = MaterialTheme.typography.bodySmall)
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { perform("toggle", notice.id) },
                            ) { Text(if (notice.isLive) "লুকান" else "প্রকাশ করুন") }
                            OutlinedButton(
                                enabled = !busy,
                                onClick = { deleteNotice = notice },
                            ) { Text("মুছুন") }
                        }
                    }
                }
            }
        }
    }

    deleteNotice?.let { notice ->
        AlertDialog(
            onDismissRequest = { deleteNotice = null },
            title = { Text("নোটিশ মুছবেন?") },
            text = { Text("নোটিশ এবং এর সংযুক্তি স্থায়ীভাবে মুছে যাবে।") },
            confirmButton = {
                Button(onClick = {
                    deleteNotice = null
                    perform("delete", notice.id)
                }) { Text("মুছুন") }
            },
            dismissButton = {
                OutlinedButton(onClick = { deleteNotice = null }) { Text("বাতিল") }
            },
        )
    }
}

private fun parseAdminNotices(rows: JSONArray): List<AdminNotice> =
    (0 until rows.length()).mapNotNull { index ->
        rows.optJSONObject(index)?.let { row ->
            AdminNotice(
                id = row.optInt("id"),
                text = row.optString("text"),
                createdAt = row.optString("created_at"),
                isLive = row.optBoolean("status"),
                attachmentUrl = row.optString("file_url").takeIf { it.isNotBlank() && it != "null" },
            )
        }
    }
