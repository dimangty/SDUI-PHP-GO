package com.shopkmp.sdui.renderer

import com.shopkmp.sdui.model.*
import kotlin.test.*

class RenderPlanTest {
    @Test fun generatedGridKeysCannotCollideWithValidServerNodeIds() {
        val text = Node.Text("grid.product.a", TextProps("Title", TextStyle.Title))
        val grid = Node.ProductGrid("products", GridProps(2), listOf(Node.Unknown("product.a")))
        val plan = verticalItems(listOf(text, grid))
        assertEquals(2, plan.map { it.lazyKey }.toSet().size)
    }

    @Test fun verticalListsAndProductGridBecomeStableOuterLazyItems() {
        val text = Node.Text("description.0", TextProps("Full description", TextStyle.Body))
        val cards = listOf("a", "b", "c").map { id -> Node.Unknown("product.$id") }
        val grid = Node.ProductGrid("products", GridProps(2), cards)
        val root = Node.Layout("root", LayoutKind.LazyColumn, LayoutProps(Space.m, Space.m, Align.Start), listOf(text, grid))
        val plan = screenRenderPlan(listOf(root)).items
        assertEquals(listOf("description.0", "grid.product.a", "grid.product.c"), plan.map { it.id })
        assertEquals(2, assertIs<RenderItem.GridRow>(plan[1]).nodes.size)
    }
}
