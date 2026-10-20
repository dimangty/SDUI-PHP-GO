package com.shopkmp.profile.data

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

class ProfileAddressParsingTest {
    @Test
    fun parsesCityStreetHouseAndApartmentWithoutDuplicatingHouse() {
        val request = "Новосибирск, Красный проспект, 1, 42".toAddressRequestOrNull()

        assertEquals("Новосибирск", request?.city)
        assertEquals("Красный проспект", request?.street)
        assertEquals("1", request?.house)
        assertEquals("42", request?.apartment)
    }

    @Test
    fun rejectsAddressWithoutRequiredParts() {
        assertNull("Новосибирск, Красный проспект".toAddressRequestOrNull())
    }
}
