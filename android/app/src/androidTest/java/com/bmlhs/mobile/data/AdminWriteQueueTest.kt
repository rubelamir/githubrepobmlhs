package com.bmlhs.mobile.data

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.net.ServerSocket
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class AdminWriteQueueTest {
    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private lateinit var queue: AdminWriteQueue
    private var server: MockWebServer? = null

    @Before
    fun setUp() {
        context.deleteDatabase("bmlhs_admin_queue.db")
        queue = AdminWriteQueue(context)
    }

    @After
    fun tearDown() {
        server?.shutdown()
        queue.close()
        context.deleteDatabase("bmlhs_admin_queue.db")
    }

    @Test
    fun encryptedQueuePersistsOwnerStateAndRetryKey() {
        val owner = 481
        val id = UUID.randomUUID().toString()
        val payload = """{"type":"json","body":"{\"action\":\"publish\",\"text\":\"offline\"}"}"""

        queue.enqueue(id, owner, "admin/notices.php", payload)

        val first = queue.list(owner).single()
        assertFalse(first.encryptedPayload.contains(payload))
        assertEquals(payload, queue.readPayload(first))
        assertEquals("pending", first.state)

        queue.mark(id, owner, "needs_confirmation", "Check current state")
        assertEquals("needs_confirmation", queue.list(owner).single().state)

        val retryId = queue.retryWithNewKey(id, owner)
        assertTrue(id != retryId)
        val retried = queue.list(owner).single()
        assertEquals(retryId, retried.id)
        assertEquals(payload, queue.readPayload(retried))
        assertTrue(queue.list(owner + 1).isEmpty())
    }

    @Test
    fun networkFailureQueuesAdminWriteAndRepeatedWriteDoesNotDuplicate() {
        val owner = 482
        val api = clientForUnusedLocalPort(owner)
        val payload = JSONObject().put("action", "publish").put("text", "offline notice")

        val firstFailure = assertThrows(AdminWriteQueuedException::class.java) {
            api.adminRequest("test-token", "admin/notices.php", "POST", payload)
        }
        val secondFailure = assertThrows(AdminWriteQueuedException::class.java) {
            api.adminRequest("test-token", "admin/notices.php", "POST", payload)
        }

        assertEquals(firstFailure.writeId, secondFailure.writeId)
        assertEquals(1, queue.list(owner).size)
        assertEquals("pending", queue.list(owner).single().state)
    }

    @Test
    fun confirmedReplayUsesOriginalIdempotencyKeyAndRemovesQueueItem() {
        val owner = 483
        val id = UUID.randomUUID().toString()
        val payload = JSONObject()
            .put("type", "json")
            .put("body", JSONObject().put("action", "publish").put("text", "offline notice").toString())
        queue.enqueue(id, owner, "admin/notices.php", payload.toString())

        val mockServer = MockWebServer()
        server = mockServer
        mockServer.start()
        mockServer.enqueue(
            MockResponse()
                .setResponseCode(201)
                .setHeader("Content-Type", "application/json")
                .setBody("""{"data":{"id":42,"status":"published"}}"""),
        )
        val api = ApiClient(mockServer.url("/api/v1/").toString(), queue)

        assertEquals(1, api.syncQueuedAdminWrites("test-token", owner))
        val request = mockServer.takeRequest()
        assertEquals("/api/v1/admin/notices.php", request.path)
        assertEquals(id, request.getHeader("Idempotency-Key"))
        assertTrue(queue.list(owner).isEmpty())
    }

    private fun clientForUnusedLocalPort(owner: Int): ApiClient {
        val port = ServerSocket(0).use { it.localPort }
        return ApiClient("http://127.0.0.1:$port/api/v1/", queue).apply {
            setAdminQueueOwner(owner)
        }
    }
}
