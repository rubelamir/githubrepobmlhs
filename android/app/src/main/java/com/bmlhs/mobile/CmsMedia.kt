package com.bmlhs.mobile

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.BitmapRegionDecoder
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Rect
import android.net.Uri
import android.provider.OpenableColumns
import android.util.Base64
import java.io.ByteArrayOutputStream
import java.io.IOException

internal data class PickedAdminFile(
    val name: String,
    val mimeType: String,
    val bytes: ByteArray,
)

internal fun readAdminUpload(context: Context, uri: Uri, maxBytes: Int = 5 * 1024 * 1024): PickedAdminFile {
    val resolver = context.contentResolver
    val name = resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { cursor ->
        if (cursor.moveToFirst()) cursor.getString(0) else null
    } ?: "upload"
    val mime = resolver.getType(uri) ?: mimeFromName(name)
    val supportedExtensions = setOf("pdf", "doc", "docx", "xls", "xlsx", "jpg", "jpeg", "png")
    val extension = name.substringAfterLast('.', "").lowercase()
    require(extension in supportedExtensions) { "PDF, Word, Excel, JPG বা PNG ফাইল বাছুন।" }
    val bytes = resolver.openInputStream(uri)?.use { input ->
        val output = ByteArrayOutputStream()
        val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
        var total = 0
        while (true) {
            val count = input.read(buffer)
            if (count < 0) break
            total += count
            require(total <= maxBytes) { "ফাইলের আকার সর্বোচ্চ ৫ MB হতে পারবে।" }
            output.write(buffer, 0, count)
        }
        output.toByteArray()
    } ?: throw IOException("Selected file could not be opened.")
    require(bytes.isNotEmpty()) { "খালি ফাইল upload করা যাবে না।" }
    return PickedAdminFile(name, mime.ifBlank { mimeFromName(name) }, bytes)
}

internal fun readCroppedJpegDataUrl(
    context: Context,
    uri: Uri,
    width: Int,
    height: Int,
    horizontalPosition: Float = 0.5f,
    verticalPosition: Float = 0.5f,
): String {
    require(width > 0 && height > 0) { "Image dimensions are invalid." }
    val resolver = context.contentResolver
    val decoder = resolver.openInputStream(uri)?.use { BitmapRegionDecoder.newInstance(it, false) }
        ?: throw IOException("Selected image could not be opened.")
    val sourceWidth = decoder.width
    val sourceHeight = decoder.height
    if (sourceWidth <= 0 || sourceHeight <= 0) {
        decoder.recycle()
        throw IllegalArgumentException("বৈধ ছবি বাছুন।")
    }
    val targetRatio = width.toFloat() / height
    val cropWidth: Int
    val cropHeight: Int
    if (sourceWidth.toFloat() / sourceHeight > targetRatio) {
        cropHeight = sourceHeight
        cropWidth = (sourceHeight * targetRatio).toInt().coerceAtLeast(1)
    } else {
        cropWidth = sourceWidth
        cropHeight = (sourceWidth / targetRatio).toInt().coerceAtLeast(1)
    }
    val horizontal = horizontalPosition.coerceIn(0f, 1f)
    val vertical = verticalPosition.coerceIn(0f, 1f)
    val left = ((sourceWidth - cropWidth) * horizontal).toInt()
    val top = ((sourceHeight - cropHeight) * vertical).toInt()
    val crop = Rect(left, top, left + cropWidth, top + cropHeight)
    val source = try {
        decoder.decodeRegion(
            crop,
            BitmapFactory.Options().apply {
                inSampleSize = calculateSampleSize(cropWidth, cropHeight, width, height)
            },
        ) ?: throw IOException("Selected image could not be decoded.")
    } finally {
        decoder.recycle()
    }
    try {
        val output = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        try {
            val paint = Paint(Paint.FILTER_BITMAP_FLAG or Paint.DITHER_FLAG)
            Canvas(output).drawBitmap(
                source,
                Rect(0, 0, source.width, source.height),
                Rect(0, 0, width, height),
                paint,
            )
            var quality = 90
            var jpeg = ByteArray(0)
            while (quality >= 40) {
                val bytes = ByteArrayOutputStream().use { stream ->
                    output.compress(Bitmap.CompressFormat.JPEG, quality, stream)
                    stream.toByteArray()
                }
                if (bytes.size <= 300 * 1024) {
                    jpeg = bytes
                    break
                }
                quality -= 5
            }
            require(jpeg.isNotEmpty()) { "ছবিটি ৩০০ KB-এর মধ্যে compress করা যায়নি।" }
            return "data:image/jpeg;base64," + Base64.encodeToString(jpeg, Base64.NO_WRAP)
        } finally {
            output.recycle()
        }
    } finally {
        source.recycle()
    }
}

private fun calculateSampleSize(sourceWidth: Int, sourceHeight: Int, targetWidth: Int, targetHeight: Int): Int {
    var sampleSize = 1
    while (
        sourceWidth / (sampleSize * 2) >= targetWidth &&
        sourceHeight / (sampleSize * 2) >= targetHeight
    ) {
        sampleSize *= 2
    }
    return sampleSize
}

private fun mimeFromName(name: String): String = when (name.substringAfterLast('.', "").lowercase()) {
    "pdf" -> "application/pdf"
    "doc" -> "application/msword"
    "docx" -> "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    "xls" -> "application/vnd.ms-excel"
    "xlsx" -> "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    "jpg", "jpeg" -> "image/jpeg"
    "png" -> "image/png"
    else -> "application/octet-stream"
}
