package com.bmlhs.mobile

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.json.JSONObject

@Composable
internal fun DownloadsContent(response: JSONObject?) {
    val uriHandler = LocalUriHandler.current
    val items = response?.optJSONObject("data")?.optJSONArray("downloads")
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("ডাউনলোড", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            if (items == null || items.length() == 0) {
                Text("এখন কোনো ফাইল প্রকাশিত নেই।", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                (0 until items.length()).forEach { index ->
                    val item = items.optJSONObject(index) ?: return@forEach
                    val title = item.optString("title")
                    val date = item.optString("published_at")
                    val url = item.optString("file_url")
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Row(
                            modifier = Modifier.padding(12.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                        ) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text(title, fontWeight = FontWeight.SemiBold)
                                Text(
                                    "${item.optString("file_type").uppercase()} · $date",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            if (url.startsWith("https://")) {
                                OutlinedButton(onClick = { uriHandler.openUri(url) }) { Text("খুলুন") }
                            }
                        }
                    }
                }
            }
        }
    }
}
