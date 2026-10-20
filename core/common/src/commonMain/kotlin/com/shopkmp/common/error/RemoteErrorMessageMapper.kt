package com.shopkmp.common.error

import com.shopkmp.remoteresult.RemoteResult

/** Converts transport failures into stable, user-facing Russian messages. */
object RemoteErrorMessageMapper {
    fun message(error: RemoteResult.Error): String = when (error) {
        RemoteResult.Error.NetworkError -> "Нет подключения к интернету. Проверьте сеть и повторите попытку."
        RemoteResult.Error.TimeoutError -> "Сервер отвечает слишком долго. Повторите попытку."
        RemoteResult.Error.SerializationError -> "Не удалось обработать ответ сервера."
        RemoteResult.Error.ServerSignError -> "Сессия истекла. Войдите снова."
        is RemoteResult.Error.ServerError -> serverMessage(error)
    }

    private fun serverMessage(error: RemoteResult.Error.ServerError): String = when (error.errorCode) {
        "INVALID_CREDENTIALS" -> "Неверный email или пароль."
        "ACCOUNT_LOCKED" -> "Вход временно заблокирован. Попробуйте позже."
        "EMAIL_ALREADY_EXISTS" -> "Аккаунт с таким email уже существует."
        "WEAK_PASSWORD" -> "Пароль должен содержать строчные и заглавные буквы и цифру."
        "OUT_OF_STOCK" -> "Товара недостаточно на складе."
        "RATE_LIMITED" -> "Слишком много попыток. Подождите и повторите."
        else -> error.message.ifBlank { "Сервер временно недоступен." }
    }
}
