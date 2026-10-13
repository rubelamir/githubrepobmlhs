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
internal fun GalleryContent(response: JSONObject?, onSelectAlbum: (Int) -> Unit, onBack: () -> Unit) {
    val uriHandler = LocalUriHandler.current
    val data = response?.optJSONObject("data")
    val selected = data?.optJSONObject("selected_album")
    val photos = data?.optJSONArray("photos")
    val albums = data?.optJSONArray("albums")

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(
                selected?.optString("title")?.takeIf(String::isNotBlank) ?: "ছবির গ্যালারি",
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
            )
            if (selected != null) {
                OutlinedButton(onClick = onBack) { Text("সব অ্যালবামে ফিরুন") }
                if (photos == null || photos.length() == 0) {
                    Text("এই অ্যালবামে কোনো ছবি নেই।")
                } else {
                    (0 until photos.length()).forEach { index ->
                        val photo = photos.optJSONObject(index) ?: return@forEach
                        Card(modifier = Modifier.fillMaxWidth()) {
                            Row(
                                modifier = Modifier.padding(12.dp),
                                horizontalArrangement = Arrangement.SpaceBetween,
                            ) {
                                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text(photo.optString("caption").ifBlank { "ছবি" }, fontWeight = FontWeight.SemiBold)
                                    Text(photo.optString("created_at"), style = MaterialTheme.typography.bodySmall)
                                }
                                val url = photo.optString("image_url")
                                if (url.startsWith("https://")) {
                                    OutlinedButton(onClick = { uriHandler.openUri(url) }) { Text("ছবি খুলুন") }
                                }
                            }
                        }
                    }
                }
            } else if (albums == null || albums.length() == 0) {
                Text("এখন কোনো অ্যালবাম প্রকাশিত নেই।")
            } else {
                (0 until albums.length()).forEach { index ->
                    val album = albums.optJSONObject(index) ?: return@forEach
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Row(
                            modifier = Modifier.padding(12.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                        ) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text(album.optString("title"), fontWeight = FontWeight.SemiBold)
                                val details = listOf(
                                    album.optString("event_name").takeIf(String::isNotBlank),
                                    album.optString("year").takeIf(String::isNotBlank),
                                    "${album.optInt("photo_count")} টি ছবি",
                                ).filterNotNull()
                                Text(details.joinToString(" · "), style = MaterialTheme.typography.bodySmall)
                            }
                            OutlinedButton(onClick = { onSelectAlbum(album.optInt("id")) }) { Text("দেখুন") }
                        }
                    }
                }
            }
        }
    }
}
