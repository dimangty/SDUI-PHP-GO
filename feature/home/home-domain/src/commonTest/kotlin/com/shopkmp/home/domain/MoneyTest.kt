package com.shopkmp.home.domain

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

class MoneyTest {
    @Test
    fun parsesCanonicalDecimalVariantsWithoutFloatingPoint() {
        assertEquals("4990.00", requireNotNull(Money.parseOrNull("4990")).toString())
        assertEquals("4990.50", requireNotNull(Money.parseOrNull("4990.5")).toString())
        assertEquals("4990.05", requireNotNull(Money.parseOrNull("4990.05")).toString())
        assertEquals("4 990,50 ₽", Money.parseOrNull("4990.50")?.formatRub())
    }

    @Test
    fun russianDisplayKeepsKopecksGroupingAndExactLargeAmounts() {
        for ((wire, display) in listOf(
            "0.00" to "0,00 ₽", "100.00" to "100,00 ₽", "0.01" to "0,01 ₽",
            "1234567.09" to "1 234 567,09 ₽",
            "92233720368547758.07" to "92 233 720 368 547 758,07 ₽",
        )) {
            val money = requireNotNull(Money.parseOrNull(wire))
            assertEquals(display, money.formatRub())
            assertEquals(wire, money.toString())
        }
    }

    @Test
    fun rejectsMalformedOrOverflowingValues() {
        listOf("", " 1.00", "01.00", "-1.00", "1.", "1.001", "1,00", "92233720368547758.08")
            .forEach { assertNull(Money.parseOrNull(it), it) }
    }

    @Test
    fun multipliesAndComparesExactAmounts() {
        val price = requireNotNull(Money.parseOrNull("99.99"))
        assertEquals("299.97", price.times(3).toString())
        assertTrue(price < requireNotNull(Money.parseOrNull("100.00")))
        assertNull(requireNotNull(Money.parseOrNull("92233720368547758.07")).times(2))
    }

    @Test
    fun cartDoesNotTurnMalformedMoneyIntoAFalseZero() {
        val product = ShopProduct("p", "Product", "Category", "", "invalid", 1, 0.0, 0)
        assertEquals("—", ShopCartItem(product, quantity = 2).totalRub)
    }
}
