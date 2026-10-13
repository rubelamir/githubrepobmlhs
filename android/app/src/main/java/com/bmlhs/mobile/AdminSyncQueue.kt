package com.bmlhs.mobile

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
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import com.bmlhs.mobile.data.QueuedAdminWrite
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
internal fun AdminWriteQueuePanel(api: ApiClient, token: String, staffId: Int) {
    val scope = rememberCoroutineScope()
    val revision by api.adminQueueRevision.collectAsState()
    var writes by remember { mutableStateOf<List<QueuedAdminWrite>>(emptyList()) }
    var syncing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    var discardTarget by remember { mutableStateOf<QueuedAdminWrite?>(null) }
    var retryTarget by remember { mutableStateOf<QueuedAdminWrite?>(null) }

    LaunchedEffect(staffId, revision) {
        writes = withContext(Dispatchers.IO) { api.queuedAdminWrites(staffId) }
    }

    if (writes.isNotEmpty()) {
        Card(Modifier.fillMaxWidth()) {
            Column(
                Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text(
                    "Offline পরিবর্তন · ${writes.size}টি",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                )
                Text("Admin ও শিক্ষক কার্যক্রমের অসম্প্রচারিত পরিবর্তন এখানে থাকবে। পাঠানোর আগে নিশ্চিত করুন।")
                writes.forEach { write ->
                    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Column(Modifier.weight(1f)) {
                                Text(write.endpoint, fontWeight = FontWeight.SemiBold)
                                Text(
                                    when (write.state) {
                                        "needs_confirmation" -> "যাচাই/নিশ্চিতকরণ দরকার"
                                        "sending" -> "পাঠানো হচ্ছে"
                                        else -> "অপেক্ষমাণ"
                                    },
                                    style = MaterialTheme.typography.bodySmall,
                                    color = if (write.state == "needs_confirmation") {
                                        MaterialTheme.colorScheme.error
                                    } else {
                                        MaterialTheme.colorScheme.onSurfaceVariant
                                    },
                                )
                            }
                            OutlinedButton(
                                enabled = !syncing,
                                onClick = { discardTarget = write },
                            ) { Text("বাদ দিন") }
                        }
                        if (write.state == "needs_confirmation") {
                            OutlinedButton(
                                enabled = !syncing,
                                onClick = { retryTarget = write },
                                modifier = Modifier.fillMaxWidth(),
                            ) { Text("ঝুঁকি বুঝে নতুন key দিয়ে আবার পাঠান") }
                        }
                        if (write.lastError.isNotBlank()) {
                            Text(write.lastError, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
                if (message.isNotBlank()) Text(message, color = MaterialTheme.colorScheme.primary)
                Button(
                    enabled = !syncing,
                    onClick = {
                        error = ""
                        message = ""
                        confirmation = "অপেক্ষমাণ ${writes.size}টি পরিবর্তন একই ক্রমে পাঠানো হবে। সার্ভারের ফল অজানা বা পরিবর্তিত থাকলে queue থেমে যাবে; পুনরায় পাঠানোর আগে সেই অবস্থা যাচাই করুন।"
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text(if (syncing) "পাঠানো হচ্ছে…" else "পরিবর্তনগুলো পাঠান") }
            }
        }
    }
    if (writes.isEmpty() && message.isNotBlank()) {
        Text(message, color = MaterialTheme.colorScheme.primary)
    }

    if (confirmation.isNotBlank()) {
        AlertDialog(
            onDismissRequest = { if (!syncing) confirmation = "" },
            title = { Text("Offline পরিবর্তন পাঠাবেন?") },
            text = { Text(confirmation) },
            confirmButton = {
                Button(
                    enabled = !syncing,
                    onClick = {
                        confirmation = ""
                        syncing = true
                        scope.launch {
                            try {
                                val count = withContext(Dispatchers.IO) {
                                    api.syncQueuedAdminWrites(token, staffId)
                                }
                                message = if (count > 0) {
                                    "${count}টি পরিবর্তন live সার্ভারে পাঠানো হয়েছে; সংশ্লিষ্ট তালিকা refresh করুন।"
                                } else {
                                    ""
                                }
                            } catch (exception: CancellationException) {
                                throw exception
                            } catch (exception: Exception) {
                                error = exception.message ?: "Queue-তে থাকা পরিবর্তনটি পাঠানো যায়নি।"
                            } finally {
                                syncing = false
                                writes = withContext(Dispatchers.IO) { api.queuedAdminWrites(staffId) }
                            }
                        }
                    },
                ) { Text("নিশ্চিত করে পাঠান") }
            },
            dismissButton = {
                OutlinedButton(onClick = { confirmation = "" }, enabled = !syncing) { Text("এখন নয়") }
            },
        )
    }

    discardTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { discardTarget = null },
            title = { Text("পরিবর্তনটি বাদ দেবেন?") },
            text = { Text("এই offline পরিবর্তন স্থায়ীভাবে মুছে যাবে; এটি সার্ভারে পাঠানো হবে না।") },
            confirmButton = {
                Button(onClick = {
                    discardTarget = null
                    scope.launch {
                        try {
                            withContext(Dispatchers.IO) { api.discardQueuedAdminWrite(staffId, target.id) }
                            error = ""
                            message = ""
                        } catch (exception: CancellationException) {
                            throw exception
                        } catch (exception: Exception) {
                            error = exception.message ?: "পরিবর্তনটি বাদ দেওয়া যায়নি।"
                        }
                    }
                }) { Text("বাদ দিন") }
            },
            dismissButton = {
                OutlinedButton(onClick = { discardTarget = null }) { Text("ফিরে যান") }
            },
        )
    }

    retryTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { if (!syncing) retryTarget = null },
            title = { Text("সম্ভাব্য duplicate তৈরি হতে পারে") },
            text = {
                Text(
                    "আগের অনুরোধটি সার্ভারে সম্পন্ন হয়েছিল কি না নিশ্চিত নয়। নতুন idempotency key দিয়ে আবার পাঠালে একই পরিবর্তন দুবার হতে পারে। বর্তমান সার্ভার অবস্থা যাচাই করে তবেই নিশ্চিত করুন।",
                )
            },
            confirmButton = {
                Button(
                    enabled = !syncing,
                    onClick = {
                        retryTarget = null
                        syncing = true
                        scope.launch {
                            try {
                                withContext(Dispatchers.IO) {
                                    api.forceRetryQueuedAdminWrite(token, staffId, target.id)
                                }
                                error = ""
                                message = "পরিবর্তনটি নতুন request key দিয়ে পাঠানো হয়েছে; সংশ্লিষ্ট তালিকা refresh করুন।"
                            } catch (exception: CancellationException) {
                                throw exception
                            } catch (exception: Exception) {
                                error = exception.message ?: "পরিবর্তনটি আবার পাঠানো যায়নি।"
                            } finally {
                                syncing = false
                                writes = withContext(Dispatchers.IO) { api.queuedAdminWrites(staffId) }
                            }
                        }
                    },
                ) { Text("ঝুঁকি বুঝে আবার পাঠান") }
            },
            dismissButton = {
                OutlinedButton(onClick = { retryTarget = null }, enabled = !syncing) { Text("বাতিল") }
            },
        )
    }
}
