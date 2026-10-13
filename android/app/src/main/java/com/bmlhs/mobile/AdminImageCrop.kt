package com.bmlhs.mobile

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.IOException
import kotlin.math.roundToInt

internal data class ImageCropRequest(val uri: Uri, val width: Int, val height: Int)

@Composable
internal fun AdminImageCropDialog(
    request: ImageCropRequest,
    onDismiss: () -> Unit,
    onConfirm: (horizontalPosition: Float, verticalPosition: Float) -> Unit,
) {
    val context = LocalContext.current
    var preview by remember(request.uri) { mutableStateOf<Bitmap?>(null) }
    var error by remember(request.uri) { mutableStateOf("") }
    var horizontal by remember(request.uri) { mutableStateOf(0.5f) }
    var vertical by remember(request.uri) { mutableStateOf(0.5f) }

    LaunchedEffect(request.uri) {
        try {
            preview = withContext(Dispatchers.IO) { readCropPreview(context, request.uri) }
        } catch (exception: IOException) {
            error = "ছবির preview পড়া যায়নি।"
        } catch (exception: IllegalArgumentException) {
            error = exception.message ?: "বৈধ ছবি বাছুন।"
        }
    }

    DisposableEffect(preview) {
        onDispose { preview?.recycle() }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("ছবির crop অবস্থান") },
        text = {
            Column(
                modifier = Modifier.fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("ছবির যে অংশটি রাখতে চান, নিচের slider দিয়ে সেটি frame-এর মধ্যে আনুন।")
                preview?.let { bitmap ->
                    CropPreview(
                        bitmap = bitmap,
                        aspectRatio = request.width.toFloat() / request.height,
                        horizontalPosition = horizontal,
                        verticalPosition = vertical,
                    )
                    if (bitmap.width.toFloat() / bitmap.height > request.width.toFloat() / request.height) {
                        Text("বাম–ডান অবস্থান")
                        Slider(value = horizontal, onValueChange = { horizontal = it })
                    }
                    if (bitmap.width.toFloat() / bitmap.height < request.width.toFloat() / request.height) {
                        Text("উপর–নিচ অবস্থান")
                        Slider(value = vertical, onValueChange = { vertical = it })
                    }
                }
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Button(enabled = preview != null && error.isBlank(), onClick = {
                onConfirm(horizontal, vertical)
            }) { Text("এই crop ব্যবহার করুন") }
        },
        dismissButton = { OutlinedButton(onClick = onDismiss) { Text("বাতিল") } },
    )
}

@Composable
private fun CropPreview(
    bitmap: Bitmap,
    aspectRatio: Float,
    horizontalPosition: Float,
    verticalPosition: Float,
) {
    BoxWithConstraints(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp)),
    ) {
        val frameWidth = maxWidth
        val frameHeight = frameWidth / aspectRatio
        val sourceRatio = bitmap.width.toFloat() / bitmap.height
        val imageWidth = if (sourceRatio > aspectRatio) frameHeight * sourceRatio else frameWidth
        val imageHeight = if (sourceRatio > aspectRatio) frameHeight else frameWidth / sourceRatio
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(frameHeight)
                .clipToBounds(),
        ) {
            Image(
                bitmap = bitmap.asImageBitmap(),
                contentDescription = "ছবির crop preview",
                contentScale = ContentScale.FillBounds,
                modifier = Modifier
                    .width(imageWidth)
                    .height(imageHeight)
                    .align(Alignment.TopStart)
                    .offset {
                        IntOffset(
                            x = -((imageWidth - frameWidth).toPx() * horizontalPosition).roundToInt(),
                            y = -((imageHeight - frameHeight).toPx() * verticalPosition).roundToInt(),
                        )
                    },
            )
        }
    }
}

private fun readCropPreview(context: Context, uri: Uri): Bitmap {
    val resolver = context.contentResolver
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
        ?: throw IOException("Selected image could not be opened.")
    if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
        throw IllegalArgumentException("বৈধ ছবি বাছুন।")
    }
    var sampleSize = 1
    while (bounds.outWidth / sampleSize > 1600 || bounds.outHeight / sampleSize > 1600) {
        sampleSize *= 2
    }
    val bitmap = resolver.openInputStream(uri)?.use { input ->
        BitmapFactory.decodeStream(
            input,
            null,
            BitmapFactory.Options().apply { inSampleSize = sampleSize },
        )
    } ?: throw IOException("Selected image could not be decoded.")
    return bitmap
}
