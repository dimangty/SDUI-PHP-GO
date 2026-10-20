package com.shopkmp.sdui.network

import com.shopkmp.network.sdui.SduiFacade
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.sdui.model.*
import kotlinx.coroutines.test.runTest
import kotlin.test.*

class NetworkSduiRepositoryTest {
    @Test fun commandAcknowledgementMustMatchVersionOutcomeCommandAndFiniteTarget() = runTest {
        val facade = TestFacade()
        val repository = NetworkSduiRepository(facade)
        val command = Command.RemoveFromCart(ProductInput("22222222-2222-4222-8222-222222222222"))
        facade.body = """{"schemaVersion":1,"command":"RemoveFromCart","outcome":"applied","refresh":{"screen":"cart"}}"""
        assertEquals(ScreenName.cart, assertIs<SduiResult.Success<Acknowledgement>>(repository.execute(command, null)).value.refresh.screen)
        listOf(
            facade.body.replace("\"schemaVersion\":1", "\"schemaVersion\":2"),
            facade.body.replace("RemoveFromCart", "UpdateQuantity"),
            facade.body.replace("applied", "unknown"),
            facade.body.replace("\"cart\"", "\"product\""),
            "{}",
        ).forEach { bad -> facade.body = bad; assertEquals(SduiError.InvalidDocument, assertIs<SduiResult.Failure>(repository.execute(command, null)).error) }
    }

    @Test fun securityAndTransportFailuresRemainDistinct() = runTest {
        val facade = TestFacade()
        val repository = NetworkSduiRepository(facade)
        facade.failure = RemoteResult.Error.ServerSignError
        assertEquals(SduiError.Unauthorized, assertIs<SduiResult.Failure>(repository.load(Destination(ScreenName.profile), "device")).error)
        facade.failure = RemoteResult.Error.TimeoutError
        assertEquals(SduiError.Network, assertIs<SduiResult.Failure>(repository.load(Destination(ScreenName.profile), "device")).error)
        facade.failure = RemoteResult.Error.ServerError(409, "Out of stock", "OUT_OF_STOCK", mapOf("quantity" to "Stock changed"))
        val error = assertIs<SduiError.Http>(assertIs<SduiResult.Failure>(repository.load(Destination(ScreenName.cart), "device")).error)
        assertEquals(409, error.status)
        assertEquals("OUT_OF_STOCK", error.code)
        assertEquals("Stock changed", error.details["quantity"])
    }
}
private class TestFacade : SduiFacade {
    var body = "{}"
    var failure: RemoteResult.Error? = null
    override suspend fun get(destination: Destination, deviceId: String) = failure ?: RemoteResult.Success(body)
    override suspend fun command(command: Command, idempotencyKey: String?) = failure ?: RemoteResult.Success(body)
    override fun newIntentKey() = "test-intent"
}
