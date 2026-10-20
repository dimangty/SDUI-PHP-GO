package com.shopkmp.sdui.network

import com.shopkmp.network.sdui.SduiFacade
import com.shopkmp.remoteresult.RemoteResult
import com.shopkmp.sdui.model.Acknowledgement
import com.shopkmp.sdui.model.Command
import com.shopkmp.sdui.model.Destination
import com.shopkmp.sdui.model.ScreenDecoder
import com.shopkmp.sdui.model.ScreenDocument
import com.shopkmp.sdui.model.ScreenResult
import com.shopkmp.sdui.model.name
import com.shopkmp.sdui.model.validateDestination
import kotlinx.serialization.json.Json
import org.koin.dsl.module

sealed interface SduiError {
    data class Http(val status: Int, val code: String?, val message: String, val details: Map<String, String> = emptyMap()) : SduiError
    data object Network : SduiError
    data object InvalidDocument : SduiError
    data object Unauthorized : SduiError
}
sealed interface SduiResult<out T> {
    data class Success<T>(val value: T) : SduiResult<T>
    data class Failure(val error: SduiError) : SduiResult<Nothing>
}
interface SduiRepository {
    suspend fun load(destination: Destination, deviceId: String): SduiResult<ScreenDocument>
    suspend fun execute(command: Command, key: String?): SduiResult<Acknowledgement>
    fun newIntentKey(): String
}
class NetworkSduiRepository(private val facade: SduiFacade) : SduiRepository {
    private val decoder = ScreenDecoder()
    private val json = Json { ignoreUnknownKeys = true }
    override fun newIntentKey() = facade.newIntentKey()
    override suspend fun load(destination: Destination, deviceId: String): SduiResult<ScreenDocument> =
        when (val result = facade.get(destination, deviceId)) {
            is RemoteResult.Success -> when (val decoded = decoder.decode(result.data)) {
                is ScreenResult.Content -> if (decoded.screen.screenId == destination.screen) SduiResult.Success(decoded.screen) else SduiResult.Failure(SduiError.InvalidDocument)
                is ScreenResult.Invalid -> SduiResult.Failure(SduiError.InvalidDocument)
            }
            is RemoteResult.Error -> SduiResult.Failure(result.toSduiError())
        }
    override suspend fun execute(command: Command, key: String?): SduiResult<Acknowledgement> =
        when (val result = facade.command(command, key)) {
            is RemoteResult.Success -> try {
                val ack = json.decodeFromString<Acknowledgement>(result.data)
                require(ack.schemaVersion == 1 && ack.outcome == "applied" && ack.command == command.name)
                validateDestination(ack.refresh)
                SduiResult.Success(ack)
            } catch (_: IllegalArgumentException) { SduiResult.Failure(SduiError.InvalidDocument) }
            is RemoteResult.Error -> SduiResult.Failure(result.toSduiError())
        }
}
private fun RemoteResult.Error.toSduiError(): SduiError = when (this) {
    RemoteResult.Error.ServerSignError -> SduiError.Unauthorized
    RemoteResult.Error.SerializationError -> SduiError.InvalidDocument
    is RemoteResult.Error.ServerError -> if (code == 401) SduiError.Unauthorized else SduiError.Http(code, errorCode, message, details.orEmpty())
    else -> SduiError.Network
}
val moduleSduiNetwork = module { single<SduiRepository> { NetworkSduiRepository(get()) } }
