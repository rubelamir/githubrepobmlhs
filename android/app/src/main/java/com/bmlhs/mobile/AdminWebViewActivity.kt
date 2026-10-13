package com.bmlhs.mobile

import android.Manifest
import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.print.PrintAttributes
import android.print.PrintManager
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.JavascriptInterface
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsControllerCompat
import com.bmlhs.mobile.BuildConfig
import java.net.URI

class AdminWebViewActivity : ComponentActivity() {
    private var webView: WebView? = null
    private var fileChooserCallback: ValueCallback<Array<Uri>>? = null
    private var pageLoading by mutableStateOf(true)
    private var pageProgress by mutableIntStateOf(0)
    private var loadError by mutableStateOf("")
    private var savedWebViewState: Bundle? = null
    private var pendingDownload: DownloadInfo? = null

    private val trustedUri: URI by lazy { URI(BuildConfig.ADMIN_WEB_SESSION_URL) }

    private val fileChooser = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val callback = fileChooserCallback
        fileChooserCallback = null
        if (callback != null) {
            val data = result.data
            val selected = when {
                result.resultCode != RESULT_OK -> null
                data?.clipData != null -> Array(data.clipData!!.itemCount) { index ->
                    data.clipData!!.getItemAt(index).uri
                }
                data?.data != null -> arrayOf(data.data!!)
                else -> null
            }
            callback.onReceiveValue(selected)
        }
    }

    private val storagePermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        val download = pendingDownload
        pendingDownload = null
        if (granted && download != null) {
            enqueueDownload(download)
        } else if (!granted) {
            Toast.makeText(this, "ফাইল ডাউনলোডের অনুমতি প্রয়োজন।", Toast.LENGTH_LONG).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (trustedUri.scheme != "https" || trustedUri.host.isNullOrBlank()) {
            finish()
            return
        }
        savedWebViewState = savedInstanceState?.getBundle(WEB_VIEW_STATE)
        WindowCompat.setDecorFitsSystemWindows(window, true)
        window.statusBarColor = android.graphics.Color.rgb(15, 47, 38)
        WindowInsetsControllerCompat(window, window.decorView).isAppearanceLightStatusBars = false

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                val current = webView
                if (current?.canGoBack() == true) current.goBack() else finish()
            }
        })

        setContent {
            MaterialTheme {
                Box(modifier = Modifier.fillMaxSize()) {
                    AndroidView(
                        modifier = Modifier.fillMaxSize(),
                        factory = { context ->
                            createWebView(context).also { view ->
                                webView = view
                                val restored = savedWebViewState?.let(view::restoreState)
                                if (restored == null) loadAuthenticatedDashboard()
                            }
                        },
                    )
                    if (pageLoading || loadError.isNotBlank()) {
                        Surface(
                            modifier = Modifier.fillMaxSize(),
                            color = Color(0xFF101815),
                        ) {
                            Column(
                                modifier = Modifier
                                    .fillMaxSize()
                                    .padding(28.dp),
                                verticalArrangement = Arrangement.Center,
                                horizontalAlignment = Alignment.CenterHorizontally,
                            ) {
                                Text("BMLHS", color = Color(0xFFE8C078), style = MaterialTheme.typography.titleMedium)
                                Text("ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়", color = Color(0xFFE5EEE9))
                                if (loadError.isNotBlank()) {
                                    Text(loadError, modifier = Modifier.padding(top = 16.dp), color = Color(0xFFE5EEE9))
                                    Button(
                                        onClick = {
                                            loadError = ""
                                            pageLoading = true
                                            loadAuthenticatedDashboard()
                                        },
                                        modifier = Modifier.padding(top = 12.dp),
                                        colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                                            containerColor = Color(0xFF246B54),
                                        ),
                                    ) {
                                        Text("আবার চেষ্টা করুন")
                                    }
                                } else {
                                    CircularProgressIndicator(
                                        modifier = Modifier.padding(top = 24.dp),
                                        color = Color(0xFF9AD3B8),
                                    )
                                    LinearProgressIndicator(
                                        progress = { pageProgress / 100f },
                                        modifier = Modifier
                                            .padding(top = 20.dp)
                                            .width(220.dp),
                                        color = Color(0xFF9AD3B8),
                                    )
                                    Text("লোড হচ্ছে…", modifier = Modifier.padding(top = 10.dp), color = Color(0xFFE5EEE9))
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private fun loadAuthenticatedDashboard() {
        val token = intent.getStringExtra(EXTRA_ACCESS_TOKEN).orEmpty()
        if (token.isBlank()) {
            pageLoading = false
            loadError = "নিরাপদ Admin Panel session তৈরি করা যায়নি। আবার লগইন করুন।"
            return
        }
        if (!hasInternetConnection()) {
            pageLoading = false
            loadError = "ইন্টারনেট সংযোগ নেই। Admin Panel দেখতে ইন্টারনেট সংযোগ চালু করে আবার চেষ্টা করুন।"
            return
        }
        webView?.loadUrl(
            BuildConfig.ADMIN_WEB_SESSION_URL,
            mapOf("Authorization" to "Bearer $token"),
        )
    }

    private fun hasInternetConnection(): Boolean {
        val manager = getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return true
        val capabilities = manager.getNetworkCapabilities(manager.activeNetwork) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun createWebView(context: Context): WebView =
        WebView(context).apply {
            // Compose AndroidView defaults to WRAP_CONTENT; a wrap-content WebView resolves
            // CSS `vh` units to 0, which collapses AdminLTE's `max-height: 100vh` sidebar.
            layoutParams = ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            )
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.cacheMode = WebSettings.LOAD_NO_CACHE
            settings.allowFileAccess = false
            settings.allowContentAccess = true
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            settings.javaScriptCanOpenWindowsAutomatically = false
            settings.setSupportMultipleWindows(false)
            CookieManager.getInstance().setAcceptCookie(true)
            addJavascriptInterface(object {
                @JavascriptInterface
                fun printCurrentPage() {
                    runOnUiThread { printPage() }
                }
            }, PRINT_BRIDGE)
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean =
                    routeUrl(request.url)

                override fun onPageStarted(view: WebView, url: String?, favicon: android.graphics.Bitmap?) {
                    super.onPageStarted(view, url, favicon)
                    pageLoading = true
                    pageProgress = 0
                    loadError = ""
                }

                override fun onPageFinished(view: WebView, url: String?) {
                    super.onPageFinished(view, url)
                    pageLoading = false
                    val pageUri = Uri.parse(url.orEmpty())
                    if (pageUri.path != Uri.parse(BuildConfig.ADMIN_WEB_SESSION_URL).path) {
                        loadError = ""
                    }
                    view.evaluateJavascript(
                        "(function(){window.print=function(){window.$PRINT_BRIDGE.printCurrentPage();};})();",
                        null,
                    )
                    if (pageUri.scheme == trustedUri.scheme && pageUri.host == trustedUri.host && pageUri.path.orEmpty().startsWith("/dist/")) {
                        view.evaluateJavascript(SIDEBAR_TOUCH_FIX, null)
                    }
                }

                override fun onReceivedHttpError(
                    view: WebView,
                    request: WebResourceRequest,
                    errorResponse: android.webkit.WebResourceResponse,
                ) {
                    super.onReceivedHttpError(view, request, errorResponse)
                    val requestUri = request.url
                    if (
                        request.isForMainFrame &&
                        requestUri.host == trustedUri.host &&
                        requestUri.path == Uri.parse(BuildConfig.ADMIN_WEB_SESSION_URL).path
                    ) {
                        pageLoading = false
                        loadError = when (errorResponse.statusCode) {
                            401, 403 -> "আপনার অ্যাপের লগইন যাচাই করা যায়নি। Admin Panel-এ প্রবেশ করতে আবার লগইন করুন।"
                            else -> "Admin Panel session তৈরি করা যায়নি (HTTP ${errorResponse.statusCode})।"
                        }
                    }
                }

                override fun onReceivedError(
                    view: WebView,
                    request: WebResourceRequest,
                    error: android.webkit.WebResourceError,
                ) {
                    super.onReceivedError(view, request, error)
                    if (request.isForMainFrame) {
                        pageLoading = false
                        loadError = if (hasInternetConnection()) {
                            "ওয়েবসাইটে সংযোগ করা যাচ্ছে না। কিছুক্ষণ পর আবার চেষ্টা করুন।"
                        } else {
                            "ইন্টারনেট সংযোগ নেই। সংযোগ চালু করে আবার চেষ্টা করুন।"
                        }
                    }
                }
            }
            webChromeClient = object : WebChromeClient() {
                override fun onProgressChanged(view: WebView, newProgress: Int) {
                    pageProgress = newProgress
                    if (newProgress >= 100) pageLoading = false
                }

                override fun onShowFileChooser(
                    webView: WebView,
                    filePathCallback: ValueCallback<Array<Uri>>,
                    fileChooserParams: WebChromeClient.FileChooserParams,
                ): Boolean {
                    fileChooserCallback?.onReceiveValue(null)
                    fileChooserCallback = filePathCallback
                    return try {
                        fileChooser.launch(fileChooserParams.createIntent())
                        true
                    } catch (_: ActivityNotFoundException) {
                        fileChooserCallback = null
                        filePathCallback.onReceiveValue(null)
                        false
                    }
                }
            }
            setDownloadListener(DownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
                downloadFile(url, userAgent, contentDisposition, mimeType)
            })
        }

    private fun routeUrl(uri: Uri): Boolean {
        if (uri.scheme == trustedUri.scheme && uri.host == trustedUri.host && uri.port == trustedUri.port) {
            return false
        }
        return try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
            true
        } catch (_: ActivityNotFoundException) {
            true
        }
    }

    private fun downloadFile(url: String, userAgent: String, contentDisposition: String?, mimeType: String?) {
        val uri = Uri.parse(url)
        if (uri.scheme != trustedUri.scheme || uri.host != trustedUri.host || uri.port != trustedUri.port) return
        val fileName = URLUtil.guessFileName(url, contentDisposition, mimeType)
        val download = DownloadInfo(url, userAgent, mimeType, fileName)
        if (
            Build.VERSION.SDK_INT <= Build.VERSION_CODES.P
            && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED
        ) {
            pendingDownload = download
            storagePermission.launch(Manifest.permission.WRITE_EXTERNAL_STORAGE)
            return
        }
        enqueueDownload(download)
    }

    private fun enqueueDownload(download: DownloadInfo) {
        val uri = Uri.parse(download.url)
        val request = DownloadManager.Request(uri)
            .setTitle(download.fileName)
            .setDescription("BMLHS Admin ফাইল ডাউনলোড")
            .setMimeType(download.mimeType ?: "application/octet-stream")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, download.fileName)
            .addRequestHeader("User-Agent", download.userAgent)
        CookieManager.getInstance().getCookie(download.url)?.let { request.addRequestHeader("Cookie", it) }
        (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
    }

    private fun printPage() {
        val current = webView ?: return
        val printManager = getSystemService(Context.PRINT_SERVICE) as PrintManager
        val title = current.title?.takeIf(String::isNotBlank) ?: "BMLHS Admin"
        printManager.print(
            title,
            current.createPrintDocumentAdapter(title),
            PrintAttributes.Builder()
                .setMediaSize(PrintAttributes.MediaSize.ISO_A4)
                .setColorMode(PrintAttributes.COLOR_MODE_COLOR)
                .build(),
        )
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView?.let { view ->
            val webViewState = Bundle()
            view.saveState(webViewState)
            outState.putBundle(WEB_VIEW_STATE, webViewState)
        }
        super.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        fileChooserCallback?.onReceiveValue(null)
        fileChooserCallback = null
        webView?.apply {
            stopLoading()
            removeJavascriptInterface(PRINT_BRIDGE)
            destroy()
        }
        webView = null
        super.onDestroy()
    }

    companion object {
        const val EXTRA_ACCESS_TOKEN = "com.bmlhs.mobile.extra.ACCESS_TOKEN"
        private const val WEB_VIEW_STATE = "admin_web_view_state"
        private const val PRINT_BRIDGE = "BmlhsAndroid"
        private val SIDEBAR_TOUCH_FIX = """
            (function() {
              if (window.__bmlhsSidebarTouchFixInstalled) return;
              window.__bmlhsSidebarTouchFixInstalled = true;
              var toggleSelector = '[data-bmlhs-sidebar-toggle], [data-lte-toggle="sidebar"]';
              var layerFix = document.createElement('style');
              layerFix.textContent = `
                .app-wrapper { isolation: isolate !important; }
                .app-header { position: relative !important; z-index: 2 !important; }
                .app-main { position: relative !important; z-index: 1 !important; }
                .app-sidebar { z-index: 2147483647 !important; }
                .sidebar-overlay { position: fixed !important; z-index: 2147483646 !important; }
                @media (max-width: 991.98px) {
                  body.sidebar-open .app-sidebar {
                    position: fixed !important;
                    top: 0 !important;
                    left: 0 !important;
                    bottom: 0 !important;
                    transform: translate3d(0, 0, 0) !important;
                    width: var(--lte-sidebar-width) !important;
                    min-width: var(--lte-sidebar-width) !important;
                    max-width: var(--lte-sidebar-width) !important;
                    margin-left: 0 !important;
                  }
                }
              `;
              document.head.appendChild(layerFix);

              var sidebarPlaceholder = null;
              var sidebarOriginalStyle = null;

              function raiseSidebar() {
                var sidebar = document.querySelector('.app-sidebar');
                if (!sidebar || !document.body.classList.contains('sidebar-open')) return;
                if (!sidebarPlaceholder) {
                  sidebarPlaceholder = document.createComment('sidebar-position');
                  sidebar.parentNode.insertBefore(sidebarPlaceholder, sidebar);
                  sidebarOriginalStyle = sidebar.getAttribute('style');
                  document.body.appendChild(sidebar);
                }
                sidebar.style.setProperty('position', 'fixed', 'important');
                sidebar.style.setProperty('top', '0', 'important');
                sidebar.style.setProperty('bottom', '0', 'important');
                sidebar.style.setProperty('left', '0', 'important');
                sidebar.style.setProperty('right', 'auto', 'important');
                sidebar.style.setProperty('width', 'var(--lte-sidebar-width)', 'important');
                sidebar.style.setProperty('min-width', 'var(--lte-sidebar-width)', 'important');
                sidebar.style.setProperty('max-width', 'var(--lte-sidebar-width)', 'important');
                sidebar.style.setProperty('margin-left', '0', 'important');
                sidebar.style.setProperty('transform', 'translate3d(0, 0, 0)', 'important');
                sidebar.style.setProperty('z-index', '2147483647', 'important');
                sidebar.style.setProperty('visibility', 'visible', 'important');
                sidebar.style.setProperty('opacity', '1', 'important');
                sidebar.style.setProperty('pointer-events', 'auto', 'important');
                var overlay = document.querySelector('.sidebar-overlay');
                if (overlay) {
                  overlay.style.setProperty('position', 'fixed', 'important');
                  overlay.style.setProperty('inset', '0', 'important');
                  overlay.style.setProperty('z-index', '2147483646', 'important');
                }
              }

              function restoreSidebar() {
                var overlay = document.querySelector('.sidebar-overlay');
                if (overlay) {
                  overlay.style.removeProperty('position');
                  overlay.style.removeProperty('inset');
                  overlay.style.removeProperty('z-index');
                }
                if (!sidebarPlaceholder) return;
                var sidebar = document.querySelector('.app-sidebar');
                if (sidebar && sidebarPlaceholder.parentNode) {
                  sidebarPlaceholder.parentNode.insertBefore(sidebar, sidebarPlaceholder);
                  if (sidebarOriginalStyle === null) sidebar.removeAttribute('style');
                  else sidebar.setAttribute('style', sidebarOriginalStyle);
                }
                sidebarPlaceholder.remove();
                sidebarPlaceholder = null;
                sidebarOriginalStyle = null;
              }

              function syncSidebarLayer() {
                if (document.body.classList.contains('sidebar-open')) raiseSidebar();
                else restoreSidebar();
              }

              function toggleSidebar(toggle) {
                var body = document.body;
                var sidebar = document.querySelector('.app-sidebar');
                if (!body || !sidebar) return;

                var mobile = window.matchMedia('(max-width: 991.98px)').matches;
                var isOpen = mobile
                  ? body.classList.contains('sidebar-open')
                  : !body.classList.contains('sidebar-collapse');
                var open = !isOpen;

                if (mobile) {
                  body.classList.toggle('sidebar-open', open);
                  body.classList.toggle('sidebar-collapse', !open);
                } else {
                  body.classList.toggle('sidebar-collapse', !open);
                }
                toggle.setAttribute('aria-expanded', String(open));
                window.requestAnimationFrame(syncSidebarLayer);
              }

              new MutationObserver(syncSidebarLayer).observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
              });

              document.addEventListener('click', function(event) {
                if (!(event.target instanceof Element)) return;
                var toggle = event.target.closest(toggleSelector);
                if (!toggle) return;

                event.preventDefault();
                event.stopImmediatePropagation();
                toggleSidebar(toggle);
              }, true);

              document.addEventListener('click', function(event) {
                if (!(event.target instanceof Element) || !event.target.closest('.sidebar-overlay')) return;
                if (!document.body.classList.contains('sidebar-open')) return;

                event.preventDefault();
                event.stopImmediatePropagation();
                document.body.classList.remove('sidebar-open');
                document.body.classList.add('sidebar-collapse');
                restoreSidebar();
                document.querySelectorAll(toggleSelector).forEach(function(toggle) {
                  toggle.setAttribute('aria-expanded', 'false');
                });
              }, true);
            })();
        """.trimIndent()
    }

    private data class DownloadInfo(
        val url: String,
        val userAgent: String,
        val mimeType: String?,
        val fileName: String,
    )
}
