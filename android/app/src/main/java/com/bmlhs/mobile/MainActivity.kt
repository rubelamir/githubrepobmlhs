package com.bmlhs.mobile

import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.Bundle
import android.database.sqlite.SQLiteException
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.background
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.foundation.Image
import androidx.compose.foundation.border
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Brush
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.bmlhs.mobile.data.ApiClient
import com.bmlhs.mobile.data.AdminWriteQueue
import com.bmlhs.mobile.data.ApiException
import com.bmlhs.mobile.data.OfflineCache
import com.bmlhs.mobile.data.SecureTokenStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.collect
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.security.GeneralSecurityException
import java.time.LocalDate
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            val context = androidx.compose.ui.platform.LocalContext.current
            BmlhsApp(
                api = remember { ApiClient(adminWriteQueue = AdminWriteQueue(context)) },
                tokens = remember { SecureTokenStore(context) },
                cache = remember { OfflineCache(context) },
            )
        }
    }
}

private val Forest = Color(0xFF0F2F26)
private val ForestMid = Color(0xFF123329)
private val ForestLight = Color(0xFF1A4D3E)
private val Amber = Color(0xFFC4892A)
private val Cream = Color(0xFFF4F1E6)

private const val OFFLINE_MESSAGE = "ইন্টারনেট সংযোগ নেই। সংযোগ চালু করে আবার চেষ্টা করুন।"

private data class TokenRead(val token: String?, val error: String)

private fun isNetworkOnline(context: Context): Boolean {
    val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return true
    val capabilities = manager.getNetworkCapabilities(manager.activeNetwork) ?: return false
    return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
        capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
}

@Composable
private fun rememberIsOnline(): Boolean {
    val context = LocalContext.current
    var online by remember { mutableStateOf(isNetworkOnline(context)) }
    DisposableEffect(context) {
        val manager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                online = isNetworkOnline(context)
            }

            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
                online = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
                    capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
            }

            override fun onLost(network: Network) {
                online = isNetworkOnline(context)
            }
        }
        try {
            manager?.registerDefaultNetworkCallback(callback)
        } catch (exception: SecurityException) {
            online = true
        }
        onDispose {
            try {
                manager?.unregisterNetworkCallback(callback)
            } catch (exception: IllegalArgumentException) {
                // Callback was never registered.
            }
        }
    }
    return online
}

@Composable
private fun OfflineNotice(online: Boolean) {
    if (online) return
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xFFB3261E)),
    ) {
        Text(
            OFFLINE_MESSAGE,
            color = Color.White,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 14.dp, vertical = 10.dp),
        )
    }
}

@Composable
private fun BmlhsApp(api: ApiClient, tokens: SecureTokenStore, cache: OfflineCache) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    api.setTokenRefresher {
        val refreshToken = try {
            tokens.readRefreshToken()
        } catch (exception: IllegalStateException) {
            throw ApiException(401, exception.message ?: "সংরক্ষিত refresh session পড়া যায়নি।", exception)
        } ?: throw ApiException(401, "সেশন মেয়াদোত্তীর্ণ; আবার লগইন করুন।")
        val refreshedData = try {
            api.refresh(refreshToken).getJSONObject("data")
        } catch (exception: JSONException) {
            throw ApiException(502, "সেশন নবায়নের সার্ভার উত্তর পড়া যায়নি।", exception)
        }
        val accessToken = try {
            refreshedData.getString("access_token").takeIf(String::isNotBlank)
                ?: throw JSONException("The refreshed access token is empty.")
            refreshedData.getString("access_token")
        } catch (exception: JSONException) {
            throw ApiException(502, "সার্ভার বৈধ access token দেয়নি।", exception)
        }
        val newRefreshToken = try {
            refreshedData.getString("refresh_token").takeIf(String::isNotBlank)
                ?: throw JSONException("The refreshed token is empty.")
        } catch (exception: JSONException) {
            throw ApiException(502, "সার্ভার বৈধ refresh token দেয়নি।", exception)
        }
        try {
            tokens.writeSession(accessToken, newRefreshToken)
        } catch (exception: GeneralSecurityException) {
            throw ApiException(500, "নবায়িত session নিরাপদে সংরক্ষণ করা যায়নি।", exception)
        }
        accessToken
    }
    val tokenRead = remember {
        try {
            TokenRead(tokens.read(), "")
        } catch (exception: IllegalStateException) {
            TokenRead(null, exception.message ?: "সংরক্ষিত session পড়া যায়নি।")
        }
    }
    var error by remember { mutableStateOf(tokenRead.error) }
    var token by remember {
        mutableStateOf(
            tokenRead.token,
        )
    }
    var account by remember { mutableStateOf<JSONObject?>(null) }
    var accountSummary by remember { mutableStateOf<JSONObject?>(null) }
    var accountType by remember { mutableStateOf("staff") }
    var mobile by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var splashComplete by remember { mutableStateOf(false) }
    var showLogin by remember { mutableStateOf(false) }
    var webPanelOpened by remember { mutableStateOf(false) }
    var authRetryCount by remember { mutableIntStateOf(0) }
    var activeAdminSection by remember(token) { mutableStateOf("dashboard") }
    var networkMessage by remember { mutableStateOf("") }
    var schoolName by remember { mutableStateOf("ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়") }
    var homeData by remember { mutableStateOf<JSONObject?>(null) }
    var catalog by remember { mutableStateOf<JSONObject?>(null) }
    var activeTab by remember { mutableStateOf("home") }
    var currentData by remember { mutableStateOf<JSONObject?>(null) }
    var selectedClass by remember { mutableStateOf(0) }
    var selectedExam by remember { mutableStateOf(0) }
    var selectedAlbum by remember { mutableStateOf(0) }
    var sessionYear by remember { mutableStateOf(LocalDate.now().year.toString()) }
    val online = rememberIsOnline()

    LaunchedEffect(token, account, accountSummary, webPanelOpened) {
        if (token == null) {
            webPanelOpened = false
        } else if (account != null && accountSummary != null && !webPanelOpened) {
            val currentAccessToken = try {
                withContext(Dispatchers.IO) { tokens.read() }
            } catch (exception: IllegalStateException) {
                error = exception.message ?: "সংরক্ষিত session পড়া যায়নি; আবার লগইন করুন।"
                null
            }
            if (currentAccessToken.isNullOrBlank()) {
                error = "সংরক্ষিত session পাওয়া যায়নি; আবার লগইন করুন।"
            } else {
                webPanelOpened = true
                context.startActivity(
                    Intent(context, AdminWebViewActivity::class.java)
                        .putExtra(AdminWebViewActivity.EXTRA_ACCESS_TOKEN, currentAccessToken),
                )
            }
        }
    }

    val loadPublic: (String, Int?, Int?) -> Unit = { tab, classOverride, examOverride ->
        activeTab = tab
        error = ""
        val requestClass = classOverride ?: selectedClass
        val requestExam = examOverride ?: selectedExam
        val requestAlbum = classOverride ?: selectedAlbum
        if (tab == "routine" && requestClass < 1) {
            error = "প্রথমে একটি শ্রেণী নির্বাচন করুন।"
        } else if (tab == "results" && (requestClass < 1 || requestExam < 1)) {
            error = "ফলাফল দেখতে শ্রেণী ও প্রকাশিত পরীক্ষা নির্বাচন করুন।"
        } else {
            busy = true
            scope.launch {
                val key = when (tab) {
                    "home" -> "public:home"
                    "calendar" -> "public:calendar"
                    "staff" -> "public:staff"
                    "downloads" -> "public:downloads"
                    "gallery" -> "public:gallery:$requestAlbum"
                    "routine" -> "public:routine:$requestClass"
                    "results" -> "public:results:$requestClass:$requestExam:$sessionYear"
                    else -> "public:home"
                }
                val cached = withContext(Dispatchers.IO) { cache.read(key) }
                if (cached != null) {
                    try {
                        currentData = JSONObject(cached.payload)
                        if (tab == "home") homeData = currentData
                    } catch (exception: JSONException) {
                        withContext(Dispatchers.IO) { cache.remove(key) }
                        networkMessage = "সংরক্ষিত তথ্য পড়া যায়নি; নতুন করে লোডের চেষ্টা হচ্ছে।"
                    }
                }
                try {
                    val response = withContext(Dispatchers.IO) {
                        when (tab) {
                            "home" -> api.publicHome()
                            "calendar" -> api.publicCalendar(LocalDate.now().year, LocalDate.now().monthValue)
                            "staff" -> api.publicStaff()
                            "downloads" -> api.publicDownloads()
                            "gallery" -> api.publicGallery(requestAlbum.takeIf { it > 0 })
                            "routine" -> api.publicRoutine(requestClass)
                            "results" -> api.publicResults(requestClass, requestExam, sessionYear)
                            else -> api.publicHome()
                        }
                    }
                    withContext(Dispatchers.IO) { cache.write(key, response.toString()) }
                    currentData = response
                    if (tab == "home") {
                        homeData = response
                        schoolName = response.optJSONObject("data")?.optJSONObject("school")
                            ?.optString("school_name_bn") ?: schoolName
                    }
                    networkMessage = ""
                } catch (exception: ApiException) {
                    networkMessage = if (cached != null) {
                        "Offline · সংরক্ষিত তথ্য দেখানো হচ্ছে"
                    } else {
                        "সার্ভার তথ্য দিতে পারেনি (${exception.statusCode})।"
                    }
                    if (cached == null) error = exception.message
                } catch (exception: IOException) {
                    networkMessage = if (cached != null) {
                        "Offline · সংরক্ষিত তথ্য দেখানো হচ্ছে"
                    } else {
                        "সার্ভারের সাথে সংযোগ করা যায়নি।"
                    }
                } catch (exception: JSONException) {
                    error = "সার্ভারের উত্তর পড়া যায়নি।"
                } finally {
                    busy = false
                }
            }
        }
    }

    LaunchedEffect(Unit) {
        delay(1100)
        splashComplete = true
    }

    LaunchedEffect(api) {
        api.sessionExpired.collect { expired ->
            if (!expired) return@collect
            api.clearExpiredSessionFlag()
            api.clearTokenReplacements()
            api.setAdminQueueOwner(0)
            token = null
            account = null
            accountSummary = null
            password = ""
            showLogin = false
            error = "আপনার সেশন শেষ হয়েছে। আবার লগইন করুন।"
            try {
                withContext(Dispatchers.IO) {
                    tokens.clear()
                    cache.clearPrivateData()
                }
            } catch (exception: SQLiteException) {
                error = "আপনার সেশন শেষ হয়েছে, তবে স্থানীয় তথ্য পরিষ্কার করা যায়নি। অ্যাপটি আবার খুলুন।"
            }
        }
    }

    LaunchedEffect(token, authRetryCount) {
        val activeToken = token ?: return@LaunchedEffect
        busy = true
        try {
            val me = withContext(Dispatchers.IO) { api.me(activeToken) }
            val data = me.optJSONObject("data")
                ?: throw JSONException("Authenticated account response did not include data.")
            account = data.optJSONObject("account")
                ?: throw JSONException("Authenticated account response did not include an account.")
            accountType = data?.optString("account_type", "") ?: ""
            if (accountType != "staff") {
                api.clearTokenReplacements()
                try {
                    withContext(Dispatchers.IO) {
                        tokens.clear()
                        cache.clearPrivateData()
                    }
                } catch (exception: SQLiteException) {
                    error = "এই অ্যাপে শুধু শিক্ষক ও অনুমোদিত Admin account ব্যবহার করতে পারবেন। স্থানীয় তথ্য পরিষ্কার করতে অ্যাপটি আবার খুলুন।"
                } finally {
                    api.setAdminQueueOwner(0)
                    api.clearTokenReplacements()
                    account = null
                    accountSummary = null
                    token = null
                }
                if (error.isBlank()) error = "এই অ্যাপে শুধু শিক্ষক ও অনুমোদিত Admin account ব্যবহার করতে পারবেন।"
                return@LaunchedEffect
            }
            api.setAdminQueueOwner(
                account?.optInt("id") ?: 0,
            )
            val summary = withContext(Dispatchers.IO) {
                api.accountSummary(activeToken, LocalDate.now().year.toString())
            }
            accountSummary = summary.optJSONObject("data")
                ?: throw JSONException("Account summary response did not include data.")
            error = ""
        } catch (exception: ApiException) {
            if (exception.statusCode == 401) {
                var cleanupFailed = false
                try {
                    withContext(Dispatchers.IO) {
                        tokens.clear()
                        cache.clearPrivateData()
                    }
                } catch (cleanupException: SQLiteException) {
                    cleanupFailed = true
                } finally {
                    account = null
                    accountSummary = null
                    api.setAdminQueueOwner(0)
                    api.clearTokenReplacements()
                    token = null
                }
                error = if (cleanupFailed) {
                    "সেশন মেয়াদোত্তীর্ণ; আবার লগইন করুন। স্থানীয় তথ্য পরিষ্কার করা যায়নি।"
                } else {
                    "সেশন মেয়াদোত্তীর্ণ; আবার লগইন করুন।"
                }
            } else {
                error = exception.message
            }
        } catch (exception: IOException) {
            error = "অ্যাকাউন্ট যাচাই করতে নেটওয়ার্ক সংযোগ পাওয়া যায়নি।"
        } catch (exception: JSONException) {
            error = "সার্ভারের উত্তর পড়া যায়নি।"
        } catch (exception: SQLiteException) {
            account = null
            accountSummary = null
            api.setAdminQueueOwner(0)
            token = null
            tokens.clear()
            error = "অ্যাপের সংরক্ষিত তথ্য পড়া বা পরিষ্কার করা যায়নি। অ্যাপটি আবার খুলুন; সমস্যা থাকলে সহায়তা নিন।"
        } finally {
            busy = false
        }
    }

    val darkMode = isSystemInDarkTheme()
    val appColors = if (darkMode) {
        darkColorScheme(
            primary = Color(0xFF9AD3B8),
            secondary = Color(0xFFE8C078),
            background = Color(0xFF101815),
            surface = Color(0xFF18231E),
            onSurface = Color(0xFFE5EEE9),
            onSurfaceVariant = Color(0xFFB7C8BE),
            primaryContainer = Color(0xFF244638),
            onPrimaryContainer = Color.White,
        )
    } else {
        lightColorScheme(
            primary = Forest,
            secondary = Amber,
            background = Color(0xFFF5F8F6),
            surface = Color.White,
            onSurfaceVariant = Color(0xFF5A6F66),
            primaryContainer = Forest,
            onPrimaryContainer = Color.White,
        )
    }
    MaterialTheme(colorScheme = appColors) {
        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            when {
                !splashComplete -> AppSplashScreen()
                token == null && !showLogin -> WelcomeScreen(
                    message = error,
                    online = online,
                    onLogin = {
                        error = ""
                        showLogin = true
                    },
                )
                token == null -> LoginScreen(
                    mobile = mobile,
                    onMobileChange = { mobile = it },
                    password = password,
                    onPasswordChange = { password = it },
                    busy = busy,
                    error = error,
                    online = online,
                    onBack = {
                        error = ""
                        showLogin = false
                    },
                    onLogin = {
                        if (!isNetworkOnline(context)) {
                            error = OFFLINE_MESSAGE
                            return@LoginScreen
                        }
                        busy = true
                        error = ""
                        scope.launch {
                            try {
                                val response = withContext(Dispatchers.IO) {
                                    api.login("staff", mobile.trim(), password)
                                }
                                val newToken = response.optJSONObject("data")?.optString("access_token")
                                    ?.takeIf(String::isNotBlank)
                                    ?: throw IllegalStateException("সার্ভার token দেয়নি।")
                                val refreshToken = response.optJSONObject("data")?.optString("refresh_token")
                                    ?.takeIf(String::isNotBlank)
                                withContext(Dispatchers.IO) {
                                    tokens.writeSession(newToken, refreshToken)
                                }
                                token = newToken
                                api.clearTokenReplacements()
                                api.clearExpiredSessionFlag()
                                password = ""
                            } catch (exception: ApiException) {
                                error = exception.message
                            } catch (exception: IOException) {
                                error = if (isNetworkOnline(context)) {
                                    "সার্ভারের সাথে সংযোগ করা যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।"
                                } else {
                                    OFFLINE_MESSAGE
                                }
                            } catch (exception: JSONException) {
                                error = "সার্ভারের উত্তর পড়া যায়নি।"
                            } catch (exception: GeneralSecurityException) {
                                error = "সুরক্ষিতভাবে session সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।"
                            } catch (exception: IllegalStateException) {
                                error = exception.message ?: "লগইন করা যায়নি।"
                            } finally {
                                busy = false
                            }
                        }
                    },
                )
                account != null -> AuthenticatedLandingScreen(
                    name = account?.optString("teacher_name").orEmpty(),
                    message = networkMessage.ifBlank { error },
                    online = online,
                    onOpenPanel = {
                        if (isNetworkOnline(context)) {
                            webPanelOpened = false
                        } else {
                            networkMessage = OFFLINE_MESSAGE
                        }
                    },
                    onSignOut = {
                        val current = token
                        token = null
                        account = null
                        accountSummary = null
                        password = ""
                        showLogin = false
                        webPanelOpened = false
                        api.setAdminQueueOwner(0)
                        api.clearTokenReplacements()
                        scope.launch {
                            try {
                                withContext(Dispatchers.IO) {
                                    tokens.clear()
                                    cache.clearPrivateData()
                                }
                            } catch (exception: SQLiteException) {
                                error = "স্থানীয় session পরিষ্কার করা যায়নি; অ্যাপটি আবার খুলুন।"
                            }
                            if (current != null) {
                                try {
                                    withContext(Dispatchers.IO) { api.logout(current) }
                                } catch (exception: IOException) {
                                    networkMessage = "এই ডিভাইস থেকে লগআউট হয়েছে; server session বন্ধ করতে নেটওয়ার্ক প্রয়োজন।"
                                } catch (exception: ApiException) {
                                    networkMessage = "এই ডিভাইস থেকে লগআউট হয়েছে; server session বন্ধ নিশ্চিত হয়নি।"
                                } catch (exception: JSONException) {
                                    networkMessage = "Server logout response যাচাই করা যায়নি।"
                                }
                            }
                        }
                    },
                )
                account == null -> LoadingScreen(
                    error = if (!online && error.isBlank()) OFFLINE_MESSAGE else error,
                    onRetry = { authRetryCount++ },
                    onSignInAgain = {
                        val current = token
                        token = null
                        account = null
                        accountSummary = null
                        showLogin = true
                        api.clearTokenReplacements()
                        scope.launch {
                            var cleanupError = ""
                            try {
                                withContext(Dispatchers.IO) {
                                    tokens.clear()
                                    cache.clearPrivateData()
                                }
                            } catch (exception: SQLiteException) {
                                cleanupError = "স্থানীয় session পরিষ্কার করা যায়নি; আবার চেষ্টা করুন।"
                            }
                            if (current != null) {
                                try {
                                    withContext(Dispatchers.IO) { api.logout(current) }
                                } catch (exception: IOException) {
                                    networkMessage = "সার্ভারে logout নিশ্চিত করা যায়নি।"
                                } catch (exception: ApiException) {
                                    networkMessage = "সার্ভারে logout নিশ্চিত করা যায়নি।"
                                } catch (exception: JSONException) {
                                    networkMessage = "সার্ভারের logout উত্তর যাচাই করা যায়নি।"
                                }
                            }
                            if (cleanupError.isNotBlank()) {
                                error = cleanupError
                            }
                        }
                    },
                )
                else -> androidx.compose.foundation.layout.Box(modifier = Modifier.fillMaxSize()) {
                    Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .verticalScroll(rememberScrollState())
                        .imePadding()
                        .padding(horizontal = 20.dp, vertical = 18.dp)
                        .widthIn(max = 1080.dp)
                        .align(androidx.compose.ui.Alignment.TopCenter),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(bottom = 4.dp),
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                        verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
                    ) {
                        Image(
                            painter = painterResource(R.drawable.school_logo),
                            contentDescription = "বিদ্যালয়ের লোগো",
                            modifier = Modifier.width(56.dp).height(56.dp),
                            contentScale = ContentScale.Fit,
                        )
                        Column(modifier = Modifier.weight(1f)) {
                            Text("BMLHS · ADMIN", style = MaterialTheme.typography.labelLarge, color = Amber, fontWeight = FontWeight.Bold)
                            Text(schoolName, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                        }
                    }
                    if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)

                    account?.let { authenticatedAccount ->
                        val displayName = authenticatedAccount.optString("teacher_name").takeIf(String::isNotBlank)
                            ?: authenticatedAccount.optString("name_ban").takeIf(String::isNotBlank) ?: "শিক্ষক"
                        val allowedModules = adminModules(accountSummary)
                        val destinations = adminDestinations(accountSummary, allowedModules)
                        LaunchedEffect(destinations) {
                            if (destinations.none { it.id == activeAdminSection }) {
                                activeAdminSection = "dashboard"
                            }
                        }
                        AdminDashboard(
                            displayName = displayName,
                            role = roleLabel("staff", accountSummary?.optString("role").orEmpty()),
                            section = activeAdminSection,
                            destinations = destinations,
                            onSelect = { activeAdminSection = it },
                        )
                        if (activeAdminSection == "dashboard" && (accountSummary?.optJSONArray("assigned_classes")?.length() ?: 0) > 0) {
                        JsonListCard(
                            "আপনার নির্ধারিত শ্রেণী/শাখা",
                            accountSummary?.optJSONArray("assigned_classes"),
                            listOf("class_name", "section_name", "session_year"),
                        )
                        }
                        token?.let { activeToken ->
                            Row(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .horizontalScroll(rememberScrollState()),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                destinations.forEach { destination ->
                                    NavButton(
                                        label = destination.title,
                                        selected = activeAdminSection == destination.id,
                                    ) { activeAdminSection = destination.id }
                                }
                            }
                            when (activeAdminSection) {
                                "dashboard" -> {
                                    val staffId = authenticatedAccount.optInt("id")
                                    if (staffId > 0) {
                                        AdminWriteQueuePanel(api, activeToken, staffId)
                                    }
                                    AdminSectionCards(destinations.filter { it.id != "dashboard" }) {
                                        activeAdminSection = it
                                    }
                                }
                                "teaching" -> TeacherTools(api, activeToken, accountSummary, sessionYear)
                                "notices" -> AdminNotices(api, activeToken)
                                "calendar" -> AdminCalendar(api, activeToken)
                                "headlines" -> AdminHeadlines(api, activeToken)
                                "events", "gallery", "downloads" ->
                                    AdminCmsPage(api, activeToken, allowedModules, activeAdminSection)
                                "academic" -> AdminAcademic(api, activeToken, allowedModules)
                                "students" -> AdminStudents(api, activeToken, allowedModules)
                                "routine" -> AdminRoutineScreen(
                                    api = api,
                                    token = activeToken,
                                    allowedModules = allowedModules,
                                    onError = { error = it },
                                )
                                "exam-routine" -> AdminRoutineScreen(
                                    api = api,
                                    token = activeToken,
                                    allowedModules = allowedModules,
                                    onError = { error = it },
                                    initialExamRoutine = true,
                                )
                                "results" -> AdminResultsScreen(
                                    api = api,
                                    token = activeToken,
                                    allowedModules = allowedModules,
                                    onError = { error = it },
                                )
                                "result-publication" -> AdminResultsPublicationScreen(
                                    api = api,
                                    token = activeToken,
                                    allowedModules = allowedModules,
                                    onError = { error = it },
                                )
                            }
                        }
                    }
                    OutlinedButton(
                        onClick = {
                            val current = token
                            token = null
                            account = null
                            accountSummary = null
                            password = ""
                            showLogin = false
                            activeAdminSection = "dashboard"
                            api.setAdminQueueOwner(0)
                            scope.launch {
                                withContext(Dispatchers.IO) {
                                    tokens.clear()
                                    cache.clearPrivateData()
                                }
                                if (current != null) {
                                    try {
                                        withContext(Dispatchers.IO) { api.logout(current) }
                                    } catch (exception: IOException) {
                                        networkMessage = "এই ডিভাইস থেকে লগআউট হয়েছে; সার্ভার token revoke করতে সংযোগ প্রয়োজন।"
                                    } catch (exception: ApiException) {
                                        networkMessage = "এই ডিভাইস থেকে লগআউট হয়েছে; সার্ভার token revoke নিশ্চিত হয়নি।"
                                    } catch (exception: JSONException) {
                                        networkMessage = "এই ডিভাইস থেকে লগআউট হয়েছে; server response যাচাই করা যায়নি।"
                                    } catch (exception: SQLiteException) {
                                        networkMessage = "স্থানীয় সেশন পরিষ্কার করা যায়নি। অ্যাপটি আবার খুলুন।"
                                    }
                                }
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("নিরাপদে লগআউট") }
                }
                }
        }
    }
}
}

@Composable
private fun NavButton(label: String, selected: Boolean, onClick: () -> Unit) {
    if (selected) Button(onClick = onClick) { Text(label) } else OutlinedButton(onClick = onClick) { Text(label) }
}

private data class AdminDestination(val id: String, val title: String, val description: String)

private fun adminDestinations(summary: JSONObject?, modules: Set<String>): List<AdminDestination> {
    val permissions = summary?.optJSONObject("permissions")
    val hasTeachingAssignments =
        (summary?.optJSONArray("assigned_classes")?.length() ?: 0) > 0 ||
            (summary?.optJSONArray("assigned_subject_classes")?.length() ?: 0) > 0
    val destinations = mutableListOf(
        AdminDestination("dashboard", "ড্যাশবোর্ড", "অ্যাকাউন্ট ও অনুমোদিত মডিউল"),
    )
    if (hasTeachingAssignments) {
        destinations += AdminDestination("teaching", "শিক্ষক কার্যক্রম", "উপস্থিতি ও নম্বর")
    }
    if (permissions?.optBoolean("can_manage_notices", false) == true || "notices" in modules) {
        destinations += AdminDestination("notices", "নোটিশ", "নোটিশ প্রকাশ ও সংযুক্তি")
    }
    if (permissions?.optBoolean("can_manage_calendar", false) == true || "events" in modules) {
        destinations += AdminDestination("calendar", "ক্যালেন্ডার", "একাডেমিক ক্যালেন্ডার")
    }
    if (permissions?.optBoolean("can_manage_headlines", false) == true || "headlines" in modules) {
        destinations += AdminDestination("headlines", "শিরোনাম", "ওয়েবসাইটের চলমান শিরোনাম")
    }
    if ("events" in modules) {
        destinations += AdminDestination("events", "ইভেন্ট", "ইভেন্ট ও ছবি")
    }
    if ("cms-gallery" in modules) {
        destinations += AdminDestination("gallery", "ফটো গ্যালারি", "অ্যালবাম ও ছবি")
    }
    if ("downloads" in modules) {
        destinations += AdminDestination("downloads", "ডাউনলোড", "ফাইল ও নথি")
    }
    val canManageAcademics = modules.any { it in setOf("classes", "sections", "subjects", "exams", "periods") }
    if (canManageAcademics) destinations += AdminDestination("academic", "একাডেমিক", "শ্রেণি, বিষয় ও পরীক্ষা")
    if (modules.any { it in setOf("students", "promotions") }) {
        destinations += AdminDestination("students", "শিক্ষার্থী", "তথ্য, ভর্তি ও প্রমোশন")
    }
    if ("routine" in modules) {
        destinations += AdminDestination("routine", "ক্লাস রুটিন", "ক্লাসের সময়সূচি")
    }
    if ("exam-routine" in modules) {
        destinations += AdminDestination("exam-routine", "পরীক্ষার রুটিন", "পরীক্ষার সময়সূচি")
    }
    if ("results" in modules || summary?.optString("role") == "teacher") {
        destinations += AdminDestination("results", "ফলাফল", "অনুমোদিত ফলাফল ও নম্বরপত্র")
    }
    if ("result-publication" in modules) {
        destinations += AdminDestination("result-publication", "ফলাফল প্রকাশনা", "ফলাফল প্রকাশ বা গোপন")
    }
    return destinations
}

@Composable
private fun AdminDashboard(
    displayName: String,
    role: String,
    section: String,
    destinations: List<AdminDestination>,
    onSelect: (String) -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .background(
                    Brush.horizontalGradient(
                        listOf(MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.primaryContainer),
                    ),
                )
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("শুভেচ্ছা, $displayName", style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.onPrimaryContainer, fontWeight = FontWeight.Bold)
            Text("$role · নিরাপদ Admin session", color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.82f))
            if (section == "dashboard") {
                Text(
                    "${destinations.size - 1}টি অনুমোদিত মডিউল উপলভ্য",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.secondary,
                )
            }
        }
    }
}

@Composable
private fun AdminSectionCards(destinations: List<AdminDestination>, onSelect: (String) -> Unit) {
    destinations.forEach { destination ->
        Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(18.dp)) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Text(destination.title, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                    Text(destination.description, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                OutlinedButton(onClick = { onSelect(destination.id) }) { Text("খুলুন") }
            }
        }
    }
}

@Composable
private fun ChoiceList(items: JSONArray?, selectedId: Int, onSelect: (Int) -> Unit) {
    if (items == null || items.length() == 0) {
        Text("এখন কোনো অপশন প্রকাশিত নেই।", color = MaterialTheme.colorScheme.onSurfaceVariant)
        return
    }
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        (0 until items.length()).forEach { index ->
            val item = items.optJSONObject(index) ?: return@forEach
            val id = item.optInt("id")
            val name = item.optString("name", "—")
            if (id == selectedId) {
                Button(onClick = { onSelect(id) }, modifier = Modifier.fillMaxWidth()) { Text(name) }
            } else {
                OutlinedButton(onClick = { onSelect(id) }, modifier = Modifier.fillMaxWidth()) { Text(name) }
            }
        }
    }
}

@Composable
private fun HomeContent(response: JSONObject?) {
    val data = response?.optJSONObject("data")
    JsonListCard("শিরোনাম / Headlines", data?.optJSONArray("headlines"), listOf("text", "created_at"))
    JsonListCard("সাম্প্রতিক নোটিশ", data?.optJSONArray("notices"), listOf("title", "published_at"))
}

@Composable
private fun CalendarContent(response: JSONObject?) {
    val data = response?.optJSONObject("data")
    JsonListCard("একাডেমিক ক্যালেন্ডার", data?.optJSONArray("calendar_items"), listOf("event_date", "title", "type_label", "description"))
    JsonListCard("ইভেন্ট", data?.optJSONArray("events"), listOf("published_at", "title", "details"))
}

@Composable
private fun StaffContent(response: JSONObject?) {
    JsonListCard("শিক্ষক ও কর্মচারীবৃন্দ", response?.optJSONObject("data")?.optJSONArray("staff"), listOf("name", "designation", "subject"))
}

@Composable
private fun RoutineContent(response: JSONObject?) {
    val data = response?.optJSONObject("data")
    JsonListCard(
        "শ্রেণী রুটিন",
        data?.optJSONArray("routine"),
        listOf("day_of_week", "period_no", "subject_name", "teacher_name", "room"),
    )
}

@Composable
private fun ResultsContent(response: JSONObject?) {
    JsonListCard("প্রকাশিত ফলাফল", response?.optJSONObject("data")?.optJSONArray("results"), listOf("rank", "roll", "name", "total", "gpa", "grade"))
}

@Composable
private fun JsonListCard(title: String, items: JSONArray?, fields: List<String>) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            if (items == null || items.length() == 0) {
                Text("দেখানোর মতো তথ্য নেই।", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                val limit = minOf(items.length(), 80)
                (0 until limit).forEach { index ->
                    val item = items.optJSONObject(index) ?: return@forEach
                    val lines = fields.mapNotNull { field ->
                        val value = item.optString(field, "").trim()
                        value.takeIf { it.isNotBlank() && it != "null" }
                    }
                    if (lines.isNotEmpty()) {
                        Text(lines.joinToString(" · "), style = MaterialTheme.typography.bodyMedium)
                    }
                }
                if (items.length() > limit) {
                    Text("প্রথম $limit টি দেখানো হয়েছে।", color = Color.Gray, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

/**
 * Mirrors the website preloader (`bmlhs/css/critical.css` `.school-preloader`):
 * forest gradient background, logo inside a ring, Bengali + English school name
 * and an amber progress bar.
 */
@Composable
private fun AppSplashScreen() {
    androidx.compose.foundation.layout.Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.linearGradient(
                    colorStops = arrayOf(0f to Forest, 0.55f to ForestMid, 1f to ForestLight),
                ),
            ),
        contentAlignment = androidx.compose.ui.Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 420.dp)
                .fillMaxWidth()
                .padding(horizontal = 28.dp),
            horizontalAlignment = androidx.compose.ui.Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            androidx.compose.foundation.layout.Box(
                modifier = Modifier
                    .width(112.dp)
                    .height(112.dp)
                    .clip(CircleShape)
                    .background(Color.White.copy(alpha = 0.08f))
                    .border(3.dp, Amber.copy(alpha = 0.85f), CircleShape)
                    .padding(14.dp),
                contentAlignment = androidx.compose.ui.Alignment.Center,
            ) {
                Image(
                    painter = painterResource(R.drawable.school_logo),
                    contentDescription = "ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের লোগো",
                    modifier = Modifier.fillMaxSize(),
                    contentScale = ContentScale.Fit,
                )
            }
            Spacer(Modifier.height(6.dp))
            Text(
                "ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়",
                style = MaterialTheme.typography.headlineSmall,
                color = Color.White,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Text(
                "Bhabanigonj ML High School",
                style = MaterialTheme.typography.bodyMedium,
                color = Cream.copy(alpha = 0.85f),
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(14.dp))
            LinearProgressIndicator(
                modifier = Modifier
                    .fillMaxWidth(0.6f)
                    .height(4.dp)
                    .clip(RoundedCornerShape(4.dp)),
                color = Amber,
                trackColor = Color.White.copy(alpha = 0.18f),
            )
            Text(
                "লোড হচ্ছে…",
                style = MaterialTheme.typography.bodySmall,
                color = Cream.copy(alpha = 0.7f),
            )
        }
    }
}

@Composable
private fun WelcomeScreen(message: String, online: Boolean, onLogin: () -> Unit) {
    BrandedScreen {
        SchoolEmblem()
        OfflineNotice(online)
        Text(
            "ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়",
            style = MaterialTheme.typography.headlineSmall,
            color = MaterialTheme.colorScheme.primary,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
        )
        Text(
            "শিক্ষক ও অনুমোদিত প্রশাসকদের জন্য",
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
        ) {
            Column(
                modifier = Modifier.padding(22.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text("Admin Panel", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                Text("আপনার নির্ধারিত অনুমতি অনুযায়ী স্কুলের কার্যক্রম পরিচালনা করুন।", color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (message.isNotBlank()) Text(message, color = MaterialTheme.colorScheme.error)
                Button(onClick = onLogin, enabled = online, modifier = Modifier.fillMaxWidth()) {
                    Text(if (online) "এডমিন / শিক্ষক লগইন" else "ইন্টারনেট সংযোগ নেই")
                }
            }
        }
    }
}

@Composable
private fun LoginScreen(
    mobile: String,
    onMobileChange: (String) -> Unit,
    password: String,
    onPasswordChange: (String) -> Unit,
    busy: Boolean,
    error: String,
    online: Boolean,
    onBack: () -> Unit,
    onLogin: () -> Unit,
) {
    BrandedScreen {
        SchoolEmblem()
        OfflineNotice(online)
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
        ) {
            Column(
                modifier = Modifier.padding(22.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                Text("এডমিন / শিক্ষক লগইন", style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
                Text("Admin Panel-এ প্রবেশ করুন", color = MaterialTheme.colorScheme.onSurfaceVariant)
                OutlinedTextField(
                    value = mobile,
                    onValueChange = onMobileChange,
                    label = { Text("মোবাইল নম্বর") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                OutlinedTextField(
                    value = password,
                    onValueChange = onPasswordChange,
                    label = { Text("পাসওয়ার্ড") },
                    modifier = Modifier.fillMaxWidth(),
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true,
                )
                if (error.isNotBlank()) {
                    Text(error, color = MaterialTheme.colorScheme.error)
                }
                Button(
                    onClick = onLogin,
                    enabled = online && !busy && mobile.isNotBlank() && password.isNotBlank(),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    when {
                        busy -> CircularProgressIndicator()
                        !online -> Text("ইন্টারনেট সংযোগ নেই")
                        else -> Text("নিরাপদে লগইন")
                    }
                }
                OutlinedButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) {
                    Text("ফিরে যান")
                }
            }
        }
    }
}

@Composable
private fun AuthenticatedLandingScreen(
    name: String,
    message: String,
    online: Boolean,
    onOpenPanel: () -> Unit,
    onSignOut: () -> Unit,
) {
    BrandedScreen {
        SchoolEmblem()
        OfflineNotice(online)
        Text(
            "স্বাগতম${name.takeIf(String::isNotBlank)?.let { ", $it" }.orEmpty()}",
            style = MaterialTheme.typography.headlineSmall,
            color = MaterialTheme.colorScheme.primary,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
        )
        Text(
            "Admin Panel-এর live ওয়েবসাইট অ্যাপের মধ্যে ব্যবহার করুন।",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        if (message.isNotBlank()) {
            Text(message, color = MaterialTheme.colorScheme.error, textAlign = TextAlign.Center)
        }
        Button(onClick = onOpenPanel, enabled = online, modifier = Modifier.fillMaxWidth()) {
            Text(if (online) "Admin Panel খুলুন" else "ইন্টারনেট সংযোগ নেই")
        }
        OutlinedButton(onClick = onSignOut, modifier = Modifier.fillMaxWidth()) {
            Text("নিরাপদে লগআউট")
        }
    }
}

@Composable
private fun LoadingScreen(error: String, onRetry: () -> Unit, onSignInAgain: () -> Unit) {
    BrandedScreen {
        CircularProgressIndicator(color = Amber)
        Text(
            if (error.isBlank()) "Admin Panel প্রস্তুত হচ্ছে…" else error,
            color = if (error.isBlank()) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
            textAlign = TextAlign.Center,
        )
        if (error.isNotBlank()) {
            OutlinedButton(onClick = onRetry) { Text("আবার চেষ্টা করুন") }
            Button(onClick = onSignInAgain) { Text("আবার লগইন করুন") }
        }
    }
}

@Composable
private fun BrandedScreen(content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit) {
    Surface(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    listOf(
                        MaterialTheme.colorScheme.background,
                        MaterialTheme.colorScheme.surface,
                        MaterialTheme.colorScheme.background,
                    ),
                ),
            ),
        color = Color.Transparent,
    ) {
        androidx.compose.foundation.layout.Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = androidx.compose.ui.Alignment.Center,
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = 560.dp)
                    .fillMaxWidth()
                    .imePadding()
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 24.dp, vertical = 34.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp, androidx.compose.ui.Alignment.CenterVertically),
                horizontalAlignment = androidx.compose.ui.Alignment.CenterHorizontally,
                content = content,
            )
        }
    }
}

@Composable
private fun SchoolEmblem() {
    Image(
        painter = painterResource(R.drawable.school_logo),
        contentDescription = "ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়ের লোগো",
        modifier = Modifier
            .padding(bottom = 8.dp)
            .width(112.dp)
            .height(112.dp),
        contentScale = ContentScale.Fit,
    )
}

@Composable
private fun FeatureCard(title: String, description: String) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(4.dp))
            Text(description, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

private fun roleLabel(accountType: String, staffRole: String): String = when {
    accountType == "student" -> "শিক্ষার্থী"
    staffRole == "head" -> "প্রধান প্রশাসক"
    staffRole == "admin" -> "প্রশাসক"
    else -> "শিক্ষক"
}

private fun adminModules(summary: JSONObject?): Set<String> {
    val modules = summary?.optJSONObject("permissions")?.optJSONArray("modules") ?: return emptySet()
    val granted = buildSet {
        for (index in 0 until modules.length()) {
            modules.optString(index).takeIf(String::isNotBlank)?.let(::add)
        }
    }
    if ("*" !in granted) return granted
    return granted + setOf(
        "classes",
        "sections",
        "subjects",
        "exams",
        "periods",
        "students",
        "promotions",
        "routine",
        "exam-routine",
        "results",
        "result-publication",
        "events",
        "downloads",
        "cms-gallery",
        "notices",
        "headlines",
    )
}
