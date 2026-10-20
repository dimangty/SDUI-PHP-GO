package com.shopkmp.main.sdui

import com.shopkmp.home.domain.CheckoutUseCase
import com.shopkmp.home.domain.ShopCheckoutResult
import com.shopkmp.home.domain.ShopPaymentMethod
import com.shopkmp.sdui.actions.CheckoutInput
import com.shopkmp.sdui.actions.PaymentUrlPolicy
import com.shopkmp.sdui.actions.Forms
import com.shopkmp.sdui.model.PaymentMethod

/** The native checkout API is deliberately five arguments; SDUI never confirms payments. */
class NativeCheckoutAdapter(
    private val checkout: CheckoutUseCase,
    private val paymentPolicy: PaymentUrlPolicy,
    private val launchPayment: (String) -> Unit,
) {
    suspend fun execute(input: CheckoutInput): NativeResult {
        val validated = Forms.checkout(mapOf(
            "checkout.city" to input.city, "checkout.street" to input.street, "checkout.house" to input.house,
            "checkout.apartment" to input.apartment, "checkout.paymentMethod" to input.paymentMethod.name,
        ))
        val paymentMethod = when (validated.paymentMethod) {
            PaymentMethod.CARD -> ShopPaymentMethod.Card
            PaymentMethod.SBP -> ShopPaymentMethod.Sbp
            PaymentMethod.ON_DELIVERY -> ShopPaymentMethod.OnDelivery
        }
        return when (val result = checkout.execute(validated.city, validated.street, validated.house, validated.apartment, paymentMethod)) {
            is ShopCheckoutResult.Error -> NativeResult.Failed("Не удалось подтвердить заказ. Обновите корзину и проверьте историю заказов перед повтором.")
            is ShopCheckoutResult.Success -> {
                val url = result.paymentUrl?.let(paymentPolicy::allowed)
                if (url != null && !result.paymentInitiationFailed) launchPayment(url)
                val message = when {
                    result.paymentInitiationFailed -> "Заказ #${result.order.number} создан. Результат оплаты неизвестен. Проверьте статус заказа; оплата автоматически не повторяется."
                    result.paymentUrl != null && url == null -> "Заказ #${result.order.number} создан. Платёжный адрес не разрешён настройками приложения. Проверьте статус заказа."
                    else -> "Заказ #${result.order.number} создан"
                }
                NativeResult.CheckoutComplete(message)
            }
        }
    }
}
