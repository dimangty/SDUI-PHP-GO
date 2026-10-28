package com.shopkmp.sdui.renderer

import com.shopkmp.sdui.model.LayoutKind
import com.shopkmp.sdui.model.Node
import com.shopkmp.sdui.model.LayoutProps
import com.shopkmp.sdui.model.Space
import com.shopkmp.sdui.model.Align

internal sealed interface RenderItem {
    val id: String
    val lazyKey: String get() = when (this) {
        is Single -> "node:$id"
        is GridRow -> "grid:${nodes.first().id}"
        is Addresses -> "local:profile-addresses"
    }
    data class Single(val node: Node) : RenderItem { override val id = node.id }
    data class GridRow(val nodes: List<Node>) : RenderItem { override val id = "grid.${nodes.first().id}" }
    data class Addresses(val nodes: List<Node>) : RenderItem { override val id = "local.profile-addresses" }
}

internal data class ScreenRenderPlan(val items: List<RenderItem>, val layout: LayoutProps)

/** Only the single vertical root is unwrapped. Nested lists retain non-scrolling layout semantics. */
internal fun screenRenderPlan(nodes: List<Node>, chromeNodeIds: Set<String> = emptySet()): ScreenRenderPlan {
    val root = (nodes.singleOrNull() as? Node.Layout)?.takeIf { it.type == LayoutKind.LazyColumn || it.type == LayoutKind.Column }
    fun body(nodes: List<Node>): List<Node> = nodes.mapNotNull { node ->
        when {
            node.id in chromeNodeIds && (node is Node.Text || node is Node.Button) -> null
            node is Node.Layout -> node.copy(children = body(node.children))
            node is Node.Form -> node.copy(children = body(node.children))
            else -> node
        }
    }
    val items = verticalItems(body(root?.children ?: nodes))
    return ScreenRenderPlan(items, root?.props ?: LayoutProps(Space.none, Space.none, Align.Start))
}

/** Grid rows remain independently lazy; nested vertical wrappers render as ordinary Columns. */
internal fun verticalItems(nodes: List<Node>): List<RenderItem> {
    val addresses = nodes.filter { it is Node.Text && it.id.startsWith("address.") }
    return nodes.flatMap { node ->
        when {
            node in addresses -> if (node == addresses.first()) listOf(RenderItem.Addresses(addresses)) else emptyList()
            node is Node.ProductGrid -> node.children.chunked(2).map { RenderItem.GridRow(it) }
            else -> listOf(RenderItem.Single(node))
        }
    }
}
