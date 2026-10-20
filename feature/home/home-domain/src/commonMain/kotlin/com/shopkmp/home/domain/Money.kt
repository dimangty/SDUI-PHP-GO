package com.shopkmp.home.domain

import kotlin.jvm.JvmInline

/**
 * Exact, non-negative RUB amount stored in kopecks.
 *
 * The backend contract uses decimal JSON strings, so parsing is deliberately
 * locale-independent and never goes through Double.
 */
@JvmInline
value class Money private constructor(
    private val kopecks: Long,
) : Comparable<Money> {
    override fun compareTo(other: Money): Int = kopecks.compareTo(other.kopecks)

    fun times(quantity: Int): Money? {
        if (quantity < 0 || quantity != 0 && kopecks > Long.MAX_VALUE / quantity) return null
        return Money(kopecks * quantity)
    }

    fun formatRub(): String {
        val rubles = kopecks / KOPECKS_PER_RUBLE
        val fraction = (kopecks % KOPECKS_PER_RUBLE).toString().padStart(2, '0')
        val groupedRubles = rubles.toString().reversed().chunked(3).joinToString(" ").reversed()
        return if (fraction == "00") "$groupedRubles RUB" else "$groupedRubles.$fraction RUB"
    }

    override fun toString(): String {
        val rubles = kopecks / KOPECKS_PER_RUBLE
        val fraction = (kopecks % KOPECKS_PER_RUBLE).toString().padStart(2, '0')
        return "$rubles.$fraction"
    }

    companion object {
        private const val KOPECKS_PER_RUBLE = 100L

        fun parseOrNull(raw: String): Money? {
            if (raw.isEmpty() || raw != raw.trim()) return null
            val separator = raw.indexOf('.')
            if (separator != raw.lastIndexOf('.')) return null

            val wholePart = if (separator < 0) raw else raw.substring(0, separator)
            val fractionPart = if (separator < 0) "" else raw.substring(separator + 1)
            if (
                !wholePart.isCanonicalDigits() ||
                separator >= 0 && fractionPart.isEmpty() ||
                fractionPart.length > 2 ||
                fractionPart.any { !it.isDigit() }
            ) {
                return null
            }

            val rubles = wholePart.toLongOrNull() ?: return null
            if (rubles > Long.MAX_VALUE / KOPECKS_PER_RUBLE) return null
            val fraction = when (fractionPart.length) {
                0 -> 0L
                1 -> fractionPart.toLong() * 10
                else -> fractionPart.toLong()
            }
            val base = rubles * KOPECKS_PER_RUBLE
            if (base > Long.MAX_VALUE - fraction) return null
            return Money(base + fraction)
        }

        private fun String.isCanonicalDigits(): Boolean =
            isNotEmpty() &&
                all(Char::isDigit) &&
                (length == 1 || first() != '0')
    }
}
