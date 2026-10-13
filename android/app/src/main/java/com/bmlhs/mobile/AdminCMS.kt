package com.bmlhs.mobile

import android.net.Uri
import android.util.Base64
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
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException

private data class CmsEvent(
    val id: Int,
    val headline: String,
    val details: String,
    val live: Boolean,
    val images: List<CmsEventImage>,
)

private data class CmsEventImage(val id: Int, val caption: String)
private data class PendingEventAction(val action: String, val eventId: Int, val imageId: Int = 0)
private data class CmsAlbum(val id: Int, val title: String, val eventName: String, val year: Int, val live: Boolean)
private data class CmsPhoto(val id: Int, val caption: String, val albumId: Int?, val live: Boolean)
private data class CmsDownload(val id: Int, val title: String, val live: Boolean)

@Composable
internal fun AdminCmsPage(api: ApiClient, token: String, allowedModules: Set<String>, page: String) {
    when (page) {
        "events" -> if ("events" in allowedModules) AdminEvents(api, token)
        "gallery" -> if ("cms-gallery" in allowedModules) AdminGallery(api, token)
        "downloads" -> if ("downloads" in allowedModules) AdminDownloads(api, token)
    }
}

@Composable
private fun AdminEvents(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var events by remember { mutableStateOf<List<CmsEvent>>(emptyList()) }
    var editing by remember { mutableStateOf<CmsEvent?>(null) }
    var headline by remember { mutableStateOf("") }
    var details by remember { mutableStateOf("") }
    var pendingAction by remember { mutableStateOf<PendingEventAction?>(null) }
    var coverCropRequest by remember { mutableStateOf<ImageCropRequest?>(null) }
    var galleryCropQueue by remember { mutableStateOf<List<Uri>>(emptyList()) }
    var galleryCropIndex by remember { mutableStateOf(0) }
    var galleryCropRequest by remember { mutableStateOf<ImageCropRequest?>(null) }
    var pendingGalleryFiles by remember { mutableStateOf<List<AdminUploadFile>>(emptyList()) }
    var coverData by remember { mutableStateOf("") }
    var coverImages by remember { mutableStateOf<List<AdminUploadFile>>(emptyList()) }
    var processingCover by remember { mutableStateOf(false) }
    var processingGallery by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val coverPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            coverCropRequest = ImageCropRequest(uri, 1280, 720)
        }
    }
    val galleryPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        if (uris.isNotEmpty()) {
            if (uris.size > 8) {
                galleryCropQueue = emptyList()
                galleryCropRequest = null
                pendingGalleryFiles = emptyList()
                error = "একটি ইভেন্টে সর্বোচ্চ ৮টি gallery ছবি বাছুন।"
            } else {
                galleryCropQueue = uris
                galleryCropIndex = 0
                pendingGalleryFiles = emptyList()
                galleryCropRequest = ImageCropRequest(uris.first(), 1200, 900)
                error = ""
            }
        }
    }

    suspend fun reload() {
        val result = withContext(Dispatchers.IO) { api.adminRequest(token, "admin/events.php") }
        events = parseCmsEvents(result.optJSONObject("data")?.optJSONArray("events") ?: JSONArray())
    }

    fun reset() {
        editing = null
        headline = ""
        details = ""
        coverData = ""
        coverImages = emptyList()
    }

    fun runAction(action: String, id: Int? = null) {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action)
                if (action == "create" || action == "update") {
                    payload.put("headline", headline.trim()).put("details", details.trim())
                    if (coverData.isNotBlank()) payload.put("events_file_data", coverData)
                    if (action == "update") payload.put("id", editing?.id)
                } else if (id != null) {
                    payload.put("id", id)
                }
                withContext(Dispatchers.IO) {
                    if ((action == "create" || action == "update") && coverImages.isNotEmpty()) {
                        api.adminMultipartRequest(
                            token,
                            "admin/events.php",
                            payloadToFields(payload),
                            coverImages,
                        )
                    } else {
                        api.adminRequest(token, "admin/events.php", "POST", payload)
                    }
                }
                if (action == "create" || action == "update") reset()
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ইভেন্ট সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "ইভেন্টের সার্ভার উত্তর পড়া যায়নি।"
            } catch (exception: IllegalArgumentException) {
                error = exception.message ?: "ইভেন্ট সংরক্ষণ করা যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "ইভেন্ট সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "ইভেন্টের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("ওয়েবসাইট ইভেন্ট", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("ইভেন্ট তৈরি/সম্পাদনা, প্রকাশনা নিয়ন্ত্রণ এবং ইভেন্টের ছবি মুছুন।")
            OutlinedTextField(
                headline,
                { headline = it.take(255) },
                label = { Text("শিরোনাম") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                details,
                { details = it.take(100_000) },
                label = { Text("বিবরণ") },
                enabled = !busy,
                minLines = 3,
                maxLines = 8,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(
                    enabled = !busy && !processingCover && !processingGallery && galleryCropRequest == null,
                    onClick = { coverPicker.launch("image/*") },
                ) {
                    Text(if (processingCover) "ছবি প্রস্তুত হচ্ছে…" else if (coverData.isBlank()) "Cover ছবি বাছুন" else "Cover ছবি বদলান")
                }
                OutlinedButton(
                    enabled = !busy && !processingCover && !processingGallery && coverCropRequest == null,
                    onClick = { galleryPicker.launch("image/*") },
                ) {
                    Text(if (processingGallery) "ছবি প্রস্তুত হচ্ছে…" else "Gallery ছবি বাছুন")
                }
            }
            if (coverData.isNotBlank()) Text("Cover প্রস্তুত · 1280×720 JPEG")
            if (coverImages.isNotEmpty()) Text("${coverImages.size}টি gallery ছবি upload-এর জন্য প্রস্তুত")
            Text(
                if (galleryCropQueue.isNotEmpty()) {
                    "Gallery ছবি ${galleryCropIndex + 1}/${galleryCropQueue.size} crop করুন (সর্বোচ্চ ৮টি, প্রতিটি ২ MB)।"
                } else {
                    "Cover ও প্রতিটি gallery ছবির crop অবস্থান বেছে নিন (সর্বোচ্চ ৮টি, প্রতিটি ২ MB)।"
                },
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    enabled = !busy && !processingCover && !processingGallery && headline.isNotBlank() && details.isNotBlank(),
                    onClick = { runAction(if (editing == null) "create" else "update") },
                ) { Text(if (editing == null) "ইভেন্ট যোগ" else "পরিবর্তন সংরক্ষণ") }
                if (editing != null) OutlinedButton(enabled = !busy, onClick = ::reset) { Text("বাতিল") }
                OutlinedButton(enabled = !busy, onClick = {
                    busy = true
                    scope.launch {
                        try {
                            reload()
                            error = ""
                        } catch (exception: ApiException) {
                            error = exception.message
                        } catch (exception: IOException) {
                            error = "ইভেন্ট সার্ভারে সংযোগ করা যায়নি।"
                        } catch (exception: JSONException) {
                            error = "ইভেন্টের সার্ভার উত্তর পড়া যায়নি।"
                        } finally {
                            busy = false
                        }
                    }
                }) { Text("Refresh") }
            }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            events.forEach { event ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(event.headline, fontWeight = FontWeight.SemiBold)
                        Text(event.details, maxLines = 4)
                        Text(if (event.live) "প্রকাশিত" else "লুকানো", style = MaterialTheme.typography.bodySmall)
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            OutlinedButton(enabled = !busy, onClick = {
                                editing = event
                                headline = event.headline
                                details = event.details
                                coverData = ""
                                coverImages = emptyList()
                            }) { Text("সম্পাদনা") }
                            OutlinedButton(enabled = !busy, onClick = { runAction("toggle", event.id) }) {
                                Text(if (event.live) "লুকান" else "প্রকাশ করুন")
                            }
                            OutlinedButton(enabled = !busy, onClick = {
                                pendingAction = PendingEventAction("delete", event.id)
                            }) {
                                Text("মুছুন")
                            }
                        }
                        event.images.forEach { image ->
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Text(image.caption.ifBlank { "ইভেন্টের ছবি #${image.id}" }, modifier = Modifier.weight(1f))
                                OutlinedButton(enabled = !busy, onClick = {
                                    pendingAction = PendingEventAction("delete_image", event.id, image.id)
                                }) { Text("ছবি মুছুন") }
                            }
                        }
                    }
                }
            }
        }
    }

    coverCropRequest?.let { request ->
        AdminImageCropDialog(
            request = request,
            onDismiss = { coverCropRequest = null },
            onConfirm = { horizontal, vertical ->
                coverCropRequest = null
                processingCover = true
                scope.launch {
                    try {
                        coverData = withContext(Dispatchers.IO) {
                            readCroppedJpegDataUrl(
                                context,
                                request.uri,
                                request.width,
                                request.height,
                                horizontal,
                                vertical,
                            )
                        }
                        error = ""
                    } catch (exception: IOException) {
                        error = "ইভেন্টের cover ছবি পড়া যায়নি।"
                    } catch (exception: IllegalArgumentException) {
                        error = exception.message ?: "বৈধ ছবি বাছুন।"
                    } finally {
                        processingCover = false
                    }
                }
            },
        )
    }

    galleryCropRequest?.let { request ->
        AdminImageCropDialog(
            request = request,
            onDismiss = {
                galleryCropRequest = null
                galleryCropQueue = emptyList()
                galleryCropIndex = 0
                pendingGalleryFiles = emptyList()
            },
            onConfirm = { horizontal, vertical ->
                galleryCropRequest = null
                processingGallery = true
                scope.launch {
                    try {
                        val data = withContext(Dispatchers.IO) {
                            readCroppedJpegDataUrl(
                                context,
                                request.uri,
                                request.width,
                                request.height,
                                horizontal,
                                vertical,
                            )
                        }
                        val croppedFile = AdminUploadFile(
                            fieldName = "event_gallery[]",
                            fileName = "event-gallery.jpg",
                            mimeType = "image/jpeg",
                            bytes = Base64.decode(data.substringAfter(','), Base64.NO_WRAP),
                        )
                        pendingGalleryFiles = pendingGalleryFiles + croppedFile
                        val nextIndex = galleryCropIndex + 1
                        if (nextIndex < galleryCropQueue.size) {
                            galleryCropIndex = nextIndex
                            galleryCropRequest = ImageCropRequest(galleryCropQueue[nextIndex], 1200, 900)
                        } else {
                            coverImages = pendingGalleryFiles
                            galleryCropQueue = emptyList()
                            galleryCropIndex = 0
                            pendingGalleryFiles = emptyList()
                        }
                        error = ""
                    } catch (exception: IOException) {
                        error = "ইভেন্টের gallery ছবি পড়া যায়নি।"
                        galleryCropQueue = emptyList()
                        pendingGalleryFiles = emptyList()
                    } catch (exception: IllegalArgumentException) {
                        error = exception.message ?: "বৈধ ছবি বাছুন।"
                        galleryCropQueue = emptyList()
                        pendingGalleryFiles = emptyList()
                    } finally {
                        processingGallery = galleryCropQueue.isNotEmpty()
                    }
                }
            },
        )
    }

    pendingAction?.let { pending ->
        AlertDialog(
            onDismissRequest = { pendingAction = null },
            title = { Text(if (pending.action == "delete_image") "ছবি মুছবেন?" else "ইভেন্ট মুছবেন?") },
            text = { Text("এই পরিবর্তনটি স্থায়ী।") },
            confirmButton = {
                Button(onClick = {
                    pendingAction = null
                    if (pending.action == "delete_image") {
                        runEventImageAction(
                            api,
                            token,
                            pending.eventId,
                            pending.imageId,
                            { reload() },
                            { error = it },
                            { busy = it },
                            scope,
                        )
                    } else {
                        runAction(pending.action, pending.eventId)
                    }
                }) { Text("মুছুন") }
            },
            dismissButton = { OutlinedButton(onClick = { pendingAction = null }) { Text("বাতিল") } },
        )
    }
}

private fun runEventImageAction(
    api: ApiClient,
    token: String,
    eventId: Int,
    imageId: Int,
    reload: suspend () -> Unit,
    showError: (String) -> Unit,
    setBusy: (Boolean) -> Unit,
    scope: kotlinx.coroutines.CoroutineScope,
) {
    setBusy(true)
    scope.launch {
        try {
            api.adminRequest(
                token,
                "admin/events.php",
                "POST",
                JSONObject().put("action", "delete_image").put("event_id", eventId).put("image_id", imageId),
            )
            reload()
        } catch (exception: ApiException) {
            showError(exception.message)
        } catch (exception: IOException) {
            showError("ইভেন্টের ছবি মুছতে সার্ভারে সংযোগ করা যায়নি।")
        } catch (exception: JSONException) {
            showError("ইভেন্টের সার্ভার উত্তর পড়া যায়নি।")
        } finally {
            setBusy(false)
        }
    }
}

@Composable
private fun AdminGallery(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var albums by remember { mutableStateOf<List<CmsAlbum>>(emptyList()) }
    var photos by remember { mutableStateOf<List<CmsPhoto>>(emptyList()) }
    var albumsAvailable by remember { mutableStateOf(true) }
    var title by remember { mutableStateOf("") }
    var eventName by remember { mutableStateOf("") }
    var year by remember { mutableStateOf(java.time.Year.now().value.toString()) }
    var albumCoverData by remember { mutableStateOf("") }
    var photoCaption by remember { mutableStateOf("") }
    var photoData by remember { mutableStateOf("") }
    var selectedAlbumId by remember { mutableStateOf("") }
    var processingImage by remember { mutableStateOf(false) }
    var pending by remember { mutableStateOf<Pair<String, Int>?>(null) }
    var cropRequest by remember { mutableStateOf<ImageCropRequest?>(null) }
    var cropTarget by remember { mutableStateOf("") }
    var photoAlbumLinkAvailable by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val coverPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            cropTarget = "album_cover"
            cropRequest = ImageCropRequest(uri, 1200, 900)
        }
    }
    val photoPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            cropTarget = "photo"
            cropRequest = ImageCropRequest(uri, 1200, 900)
        }
    }

    suspend fun reload() {
        val result = withContext(Dispatchers.IO) { api.adminRequest(token, "admin/gallery.php") }
        val data = result.optJSONObject("data")
        albums = parseCmsAlbums(data?.optJSONArray("albums") ?: JSONArray())
        photos = parseCmsPhotos(data?.optJSONArray("photos") ?: JSONArray())
        albumsAvailable = data?.optBoolean("albums_available", false) == true
        photoAlbumLinkAvailable = data?.optBoolean("photo_album_link_available", false) == true
    }

    fun act(action: String, id: Int? = null) {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action)
                if (action == "create_album") {
                    payload.put("title", title.trim())
                        .put("event_name", eventName.trim())
                        .put("album_year", year.toInt())
                    if (albumCoverData.isNotBlank()) payload.put("cover_file_data", albumCoverData)
                } else if (action == "create_photo") {
                    payload.put("caption", photoCaption.trim())
                        .put("image_gallery_file_data", photoData)
                    if (photoAlbumLinkAvailable && selectedAlbumId.isNotBlank()) {
                        payload.put("album_id", selectedAlbumId.toInt())
                    }
                } else if (id != null) {
                    payload.put("id", id)
                }
                withContext(Dispatchers.IO) { api.adminRequest(token, "admin/gallery.php", "POST", payload) }
                if (action == "create_album") {
                    title = ""
                    eventName = ""
                    albumCoverData = ""
                } else if (action == "create_photo") {
                    photoCaption = ""
                    photoData = ""
                    selectedAlbumId = ""
                }
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "গ্যালারি সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "গ্যালারির সার্ভার উত্তর পড়া যায়নি।"
            } catch (exception: NumberFormatException) {
                error = "সালটি সংখ্যায় লিখুন।"
            } catch (exception: IllegalArgumentException) {
                error = exception.message ?: "গ্যালারির তথ্য সংরক্ষণ করা যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "গ্যালারি সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "গ্যালারির সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("ফটো গ্যালারি", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("অ্যালবাম তৈরি, ছবি crop, প্রকাশনা নিয়ন্ত্রণ এবং অ্যালবাম/ছবি মুছুন।")
            if (albumsAvailable) {
                OutlinedTextField(title, { title = it.take(255) }, label = { Text("অ্যালবামের নাম") }, enabled = !busy, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(eventName, { eventName = it.take(255) }, label = { Text("অনুষ্ঠানের নাম (ঐচ্ছিক)") }, enabled = !busy, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(year, { year = it.filter(Char::isDigit).take(4) }, label = { Text("সাল") }, enabled = !busy, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedButton(enabled = !busy && !processingImage, onClick = { coverPicker.launch("image/*") }) {
                    Text(if (processingImage) "ছবি প্রস্তুত হচ্ছে…" else if (albumCoverData.isBlank()) "অ্যালবাম cover বাছুন" else "Cover প্রস্তুত · 1200×900")
                }
                Button(enabled = !busy && !processingImage && title.isNotBlank() && year.length == 4, onClick = { act("create_album") }) {
                    Text("অ্যালবাম তৈরি")
                }
            } else {
                Text("এই ডাটাবেজে অ্যালবাম সুবিধা চালু নেই।")
            }
            Text("নতুন ছবি যোগ", fontWeight = FontWeight.SemiBold)
            OutlinedTextField(
                photoCaption,
                { photoCaption = it.take(255) },
                label = { Text("ছবির caption") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            if (photoAlbumLinkAvailable && albums.isNotEmpty()) {
                CmsAlbumField(
                    label = "অ্যালবাম (ঐচ্ছিক)",
                    value = selectedAlbumId,
                    choices = listOf("" to "কোনো অ্যালবাম নয়") + albums.map { it.id.toString() to it.title },
                    enabled = !busy,
                    onValueChange = { selectedAlbumId = it },
                )
            }
            OutlinedButton(enabled = !busy && !processingImage, onClick = { photoPicker.launch("image/*") }) {
                Text(if (processingImage) "ছবি প্রস্তুত হচ্ছে…" else if (photoData.isBlank()) "ছবি বাছুন" else "ছবি প্রস্তুত · 1200×900")
            }
            Button(
                enabled = !busy && !processingImage && photoCaption.isNotBlank() && photoData.isNotBlank(),
                onClick = { act("create_photo") },
            ) { Text("ছবি যোগ করুন") }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            Text("অ্যালবাম", fontWeight = FontWeight.SemiBold)
            albums.forEach { album ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text(album.title, fontWeight = FontWeight.SemiBold)
                        Text(listOf(album.eventName, album.year.toString(), if (album.live) "প্রকাশিত" else "লুকানো").filter(String::isNotBlank).joinToString(" · "))
                    }
                    OutlinedButton(enabled = !busy, onClick = { act("toggle_album", album.id) }) {
                        Text(if (album.live) "লুকান" else "প্রকাশ")
                    }
                    OutlinedButton(enabled = !busy, onClick = { pending = "delete_album" to album.id }) { Text("মুছুন") }
                }
            }
            Text("ছবি", fontWeight = FontWeight.SemiBold)
            photos.forEach { photo ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Column(Modifier.weight(1f)) {
                        Text(photo.caption)
                        Text("${photo.albumId?.let { "অ্যালবাম #$it · " } ?: ""}${if (photo.live) "প্রকাশিত" else "লুকানো"}")
                    }
                    OutlinedButton(enabled = !busy, onClick = { act("toggle_photo", photo.id) }) {
                        Text(if (photo.live) "লুকান" else "প্রকাশ")
                    }
                    OutlinedButton(enabled = !busy, onClick = { pending = "delete_photo" to photo.id }) { Text("মুছুন") }
                }
            }
        }
    }

    cropRequest?.let { request ->
        AdminImageCropDialog(
            request = request,
            onDismiss = { cropRequest = null },
            onConfirm = { horizontal, vertical ->
                cropRequest = null
                processingImage = true
                scope.launch {
                    try {
                        val cropped = withContext(Dispatchers.IO) {
                            readCroppedJpegDataUrl(
                                context,
                                request.uri,
                                request.width,
                                request.height,
                                horizontal,
                                vertical,
                            )
                        }
                        if (cropTarget == "album_cover") albumCoverData = cropped else photoData = cropped
                        error = ""
                    } catch (exception: IOException) {
                        error = if (cropTarget == "album_cover") "অ্যালবামের cover পড়া যায়নি।" else "গ্যালারি ছবি পড়া যায়নি।"
                    } catch (exception: IllegalArgumentException) {
                        error = exception.message ?: "বৈধ ছবি বাছুন।"
                    } finally {
                        processingImage = false
                    }
                }
            },
        )
    }

    pending?.let { (action, id) ->
        AlertDialog(
            onDismissRequest = { pending = null },
            title = { Text(if (action == "delete_album") "অ্যালবাম মুছবেন?" else "ছবি মুছবেন?") },
            text = { Text(if (action == "delete_album") "অ্যালবাম মুছলে এর সাথে যুক্ত ছবি মুছে যাবে না।" else "ছবিটি স্থায়ীভাবে মুছে যাবে।") },
            confirmButton = {
                Button(onClick = {
                    pending = null
                    act(action, id)
                }) { Text("মুছুন") }
            },
            dismissButton = { OutlinedButton(onClick = { pending = null }) { Text("বাতিল") } },
        )
    }
}

@Composable
private fun CmsAlbumField(
    label: String,
    value: String,
    choices: List<Pair<String, String>>,
    enabled: Boolean,
    onValueChange: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    Column {
        Text(label, style = MaterialTheme.typography.labelLarge)
        OutlinedButton(enabled = enabled, onClick = { expanded = true }) {
            Text(choices.firstOrNull { it.first == value }?.second ?: "কোনো অ্যালবাম নয়")
        }
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            choices.forEach { (id, title) ->
                DropdownMenuItem(
                    text = { Text(title) },
                    onClick = {
                        onValueChange(id)
                        expanded = false
                    },
                )
            }
        }
    }
}

@Composable
private fun AdminDownloads(api: ApiClient, token: String) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var downloads by remember { mutableStateOf<List<CmsDownload>>(emptyList()) }
    var pending by remember { mutableStateOf<CmsDownload?>(null) }
    var title by remember { mutableStateOf("") }
    var selectedFile by remember { mutableStateOf<PickedAdminFile?>(null) }
    var error by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var processingFile by remember { mutableStateOf(false) }
    val filePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri: Uri? ->
        if (uri != null) {
            selectedFile = null
            processingFile = true
            scope.launch {
                try {
                    selectedFile = withContext(Dispatchers.IO) { readAdminUpload(context, uri) }
                    if (title.isBlank()) title = selectedFile?.name?.substringBeforeLast('.')?.take(255).orEmpty()
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
        val result = withContext(Dispatchers.IO) { api.adminRequest(token, "admin/downloads.php") }
        downloads = parseCmsDownloads(result.optJSONObject("data")?.optJSONArray("downloads") ?: JSONArray())
    }

    fun act(action: String, item: CmsDownload) {
        if (busy) return
        busy = true
        error = ""
        scope.launch {
            try {
                val payload = JSONObject().put("action", action).put("id", item.id)
                withContext(Dispatchers.IO) { api.adminRequest(token, "admin/downloads.php", "POST", payload) }
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ডাউনলোড সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "ডাউনলোডের সার্ভার উত্তর পড়া যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    fun createDownload() {
        val file = selectedFile ?: return
        if (busy || processingFile || title.isBlank()) return
        busy = true
        error = ""
        scope.launch {
            try {
                withContext(Dispatchers.IO) {
                    api.adminMultipartRequest(
                        token,
                        "admin/downloads.php",
                        fields = mapOf("action" to "create", "title" to title.trim()),
                        files = listOf(AdminUploadFile("files_file", file.name, file.mimeType, file.bytes)),
                    )
                }
                title = ""
                selectedFile = null
                reload()
            } catch (exception: ApiException) {
                error = exception.message
            } catch (exception: IOException) {
                error = "ডাউনলোড upload করতে সার্ভারে সংযোগ করা যায়নি।"
            } catch (exception: JSONException) {
                error = "ডাউনলোডের সার্ভার উত্তর পড়া যায়নি।"
            } catch (exception: IllegalArgumentException) {
                error = exception.message ?: "ডাউনলোড সংরক্ষণ করা যায়নি।"
            } finally {
                busy = false
            }
        }
    }

    LaunchedEffect(token) {
        busy = true
        try {
            reload()
        } catch (exception: ApiException) {
            error = exception.message
        } catch (exception: IOException) {
            error = "ডাউনলোড সার্ভারে সংযোগ করা যায়নি।"
        } catch (exception: JSONException) {
            error = "ডাউনলোডের সার্ভার উত্তর পড়া যায়নি।"
        } finally {
            busy = false
        }
    }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("ডাউনলোড ব্যবস্থাপনা", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("PDF, Word, Excel, JPG বা PNG · সর্বোচ্চ ৫ MB")
            OutlinedTextField(
                title,
                { title = it.take(255) },
                label = { Text("ডাউনলোডের শিরোনাম") },
                enabled = !busy,
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(enabled = !busy && !processingFile, onClick = { filePicker.launch("*/*") }) {
                    Text(if (processingFile) "ফাইল পড়া হচ্ছে…" else if (selectedFile == null) "ফাইল বাছুন" else "অন্য ফাইল বাছুন")
                }
                selectedFile?.let { Text(it.name, modifier = Modifier.padding(top = 12.dp)) }
            }
            Button(enabled = !busy && !processingFile && title.isNotBlank() && selectedFile != null, onClick = ::createDownload) {
                Text("ফাইল upload করুন")
            }
            if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            if (downloads.isEmpty() && !busy) Text("কোনো ডাউনলোড নেই।")
            downloads.forEach { item ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(item.title, fontWeight = FontWeight.SemiBold)
                        Text(if (item.live) "প্রকাশিত" else "লুকানো", style = MaterialTheme.typography.bodySmall)
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            OutlinedButton(enabled = !busy, onClick = { act("toggle", item) }) {
                                Text(if (item.live) "লুকান" else "প্রকাশ")
                            }
                            OutlinedButton(enabled = !busy, onClick = { pending = item }) { Text("মুছুন") }
                        }
                    }
                }
            }
        }
    }
    pending?.let { item ->
        AlertDialog(
            onDismissRequest = { pending = null },
            title = { Text("ডাউনলোড মুছবেন?") },
            text = { Text("“${item.title}” এবং সংরক্ষিত ফাইলটি স্থায়ীভাবে মুছে যাবে।") },
            confirmButton = {
                Button(onClick = {
                    pending = null
                    act("delete", item)
                }) { Text("মুছুন") }
            },
            dismissButton = { OutlinedButton(onClick = { pending = null }) { Text("বাতিল") } },
        )
    }
}

private fun parseCmsEvents(json: JSONArray): List<CmsEvent> = buildList {
    for (index in 0 until json.length()) {
        val item = json.optJSONObject(index) ?: continue
        val images = item.optJSONArray("images") ?: JSONArray()
        add(
            CmsEvent(
                item.optInt("id"),
                item.optString("headline"),
                item.optString("details"),
                item.optBoolean("status"),
                buildList {
                    for (imageIndex in 0 until images.length()) {
                        val image = images.optJSONObject(imageIndex) ?: continue
                        add(CmsEventImage(image.optInt("id"), if (image.isNull("caption")) "" else image.optString("caption")))
                    }
                },
            ),
        )
    }
}

private fun payloadToFields(payload: JSONObject): Map<String, String> = buildMap {
    val keys = payload.keys()
    while (keys.hasNext()) {
        val key = keys.next()
        put(key, payload.get(key).toString())
    }
}

private fun parseCmsAlbums(json: JSONArray): List<CmsAlbum> = buildList {
    for (index in 0 until json.length()) {
        val item = json.optJSONObject(index) ?: continue
        add(
            CmsAlbum(
                item.optInt("id"),
                item.optString("title"),
                if (item.isNull("event_name")) "" else item.optString("event_name"),
                item.optInt("year"),
                item.optBoolean("status"),
            ),
        )
    }
}

private fun parseCmsPhotos(json: JSONArray): List<CmsPhoto> = buildList {
    for (index in 0 until json.length()) {
        val item = json.optJSONObject(index) ?: continue
        val albumValue = item.optInt("album_id").takeIf { it > 0 }
        add(CmsPhoto(item.optInt("id"), item.optString("caption"), albumValue, item.optBoolean("status")))
    }
}

private fun parseCmsDownloads(json: JSONArray): List<CmsDownload> = buildList {
    for (index in 0 until json.length()) {
        val item = json.optJSONObject(index) ?: continue
        add(CmsDownload(item.optInt("id"), item.optString("title"), item.optBoolean("status")))
    }
}
