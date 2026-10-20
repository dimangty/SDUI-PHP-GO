package com.shopkmp.network.shop

import com.shopkmp.network.BackendSession
import com.shopkmp.network.api.request.DeleteRequest
import com.shopkmp.network.api.request.GetRequest
import com.shopkmp.network.api.request.PatchRequest
import com.shopkmp.network.api.request.PostRequest
import com.shopkmp.network.api.request.RequestExecutor
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.settings.AppSettings
import com.shopkmp.settings.MemorySettings
import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.request.HttpRequestData
import io.ktor.http.ContentType
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertNotNull

class ShopApiTest {
    @Test
    fun everyShopRouteBuildsExpectedRequest() = runTest {
        val requests = mutableListOf<HttpRequestData>()
        val api = api { request ->
            requests += request
            when (request.url.encodedPath) {
                "/api/v1/categories" -> "[]"
                "/api/v1/products", "/api/v1/products/search" ->
                    """{"items":[],"page":0,"size":20,"totalPages":0,"totalItems":0}"""
                "/api/v1/orders" ->
                    if (request.method == HttpMethod.Get) {
                        """{"items":[],"page":0,"size":20,"totalPages":0,"totalItems":0}"""
                    } else {
                        orderJson
                    }
                "/api/v1/products/popular" -> "[]"
                "/api/v1/products/p1" ->
                    """{"id":"p1","categoryId":"c1","name":"Phone","description":"D","price":"10.00","discountPrice":null,"stock":1,"rating":null,"imageUrls":[],"isActive":true,"reviewsCount":0}"""
                "/api/v1/cart" ->
                    """{"id":"cart","userId":"u1","items":[],"totalAmount":"0.00","updatedAt":"now"}"""
                "/api/v1/addresses" ->
                    if (request.method == HttpMethod.Get) "[]" else addressJson
                "/api/v1/cart/items" ->
                    """{"id":"cart","userId":"u1","items":[],"totalAmount":"0.00","updatedAt":"now"}"""
                "/api/v1/cart/items/p1" ->
                    if (request.method == HttpMethod.Delete) "" else
                        """{"id":"cart","userId":"u1","items":[],"totalAmount":"0.00","updatedAt":"now"}"""
                "/api/v1/payments/pay1/confirm" ->
                    """{"paymentId":"pay1","status":"AUTHORIZED","paymentUrl":"https://pay"}"""
                else -> orderJson
            }
        }

        assertIs<RemoteResult.Success<List<CategoryDto>>>(api.getCategories())
        assertIs<RemoteResult.Success<PageResponse<ProductSummaryDto>>>(
            api.getProducts("c1", 1, 10, "price"),
        )
        assertIs<RemoteResult.Success<PageResponse<ProductSummaryDto>>>(
            api.searchProducts("phone", "c1", "1.00", "20.00", 0, 20),
        )
        assertIs<RemoteResult.Success<List<ProductSummaryDto>>>(api.getPopularProducts(5))
        assertIs<RemoteResult.Success<ProductDto>>(api.getProduct("p1"))
        assertIs<RemoteResult.Success<CartDto>>(api.getCart())
        assertIs<RemoteResult.Success<PageResponse<OrderDto>>>(api.getOrders(0, 20, "CREATED"))
        assertIs<RemoteResult.Success<List<AddressDto>>>(api.getAddresses())
        assertIs<RemoteResult.Success<CartDto>>(
            api.addToCart(AddCartItemRequest("p1", 2), "idem-cart"),
        )
        assertIs<RemoteResult.Success<Unit>>(api.deleteCartItem("p1"))
        assertIs<RemoteResult.Success<CartDto>>(
            api.updateCartItem("p1", UpdateCartItemRequest(3)),
        )
        assertIs<RemoteResult.Success<AddressDto>>(
            api.createAddress(UpsertAddressRequest("City", "Street", "1")),
        )
        assertIs<RemoteResult.Success<OrderDto>>(
            api.createOrder(CreateOrderRequest("a1", "CARD"), "idem-order"),
        )
        assertIs<RemoteResult.Success<PaymentConfirmResponse>>(api.confirmPayment("pay1"))
        assertNotNull(api.newIdempotencyKey())

        val products = requests.first { it.url.encodedPath == "/api/v1/products" }
        assertEquals("c1", products.url.parameters["categoryId"])
        assertEquals("price", products.url.parameters["sort"])
        val search = requests.first { it.url.encodedPath.endsWith("/search") }
        assertEquals("phone", search.url.parameters["q"])
        assertEquals("1.00", search.url.parameters["priceMin"])
        val cartPost = requests.first {
            it.url.encodedPath == "/api/v1/cart/items" && it.method == HttpMethod.Post
        }
        assertEquals("idem-cart", cartPost.headers["Idempotency-Key"])
        val orderPost = requests.first {
            it.url.encodedPath == "/api/v1/orders" && it.method == HttpMethod.Post
        }
        assertEquals("idem-order", orderPost.headers["Idempotency-Key"])
    }

    private fun api(responseBody: (HttpRequestData) -> String): ShopApi {
        val json = Json {
            ignoreUnknownKeys = true
            encodeDefaults = true
            explicitNulls = false
        }
        val engine = MockEngine { request ->
            val body = responseBody(request)
            respond(
                content = body,
                status = if (request.method == HttpMethod.Delete) {
                    HttpStatusCode.NoContent
                } else {
                    HttpStatusCode.OK
                },
                headers = headersOf(HttpHeaders.ContentType, ContentType.Application.Json.toString()),
            )
        }
        val client = HttpClient(engine) {
            expectSuccess = false
            install(ContentNegotiation) { json(json) }
        }
        val session = BackendSession(AppSettings(MemorySettings())).apply {
            saveTokens("u1", "access", "refresh")
        }
        val executor = RequestExecutor(client, json, session)
        return ShopApi(
            GetRequest(executor),
            PostRequest(executor),
            PatchRequest(executor),
            DeleteRequest(executor),
        )
    }
}

private const val addressJson =
    """{"id":"a1","userId":"u1","city":"City","street":"Street","house":"1","apartment":"","comment":null}"""

private const val orderJson =
    """{"id":"o1","number":1,"userId":"u1","status":"CREATED","items":[],"totalAmount":"10.00","deliveryAddressId":"a1","paymentId":"pay1","createdAt":"now","comment":null,"paymentMethod":"CARD","deliveryAddress":"City, Street, 1"}"""
