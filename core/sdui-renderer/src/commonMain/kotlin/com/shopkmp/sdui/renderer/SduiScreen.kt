package com.shopkmp.sdui.renderer

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.runtime.Composable
import androidx.compose.runtime.key
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.text
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.shopkmp.sdui.actions.EventRegistry
import com.shopkmp.sdui.actions.SduiEvent
import com.shopkmp.sdui.model.Align
import com.shopkmp.sdui.model.Aspect
import com.shopkmp.sdui.model.Input
import com.shopkmp.sdui.model.LayoutKind
import com.shopkmp.sdui.model.MediaPolicy
import com.shopkmp.sdui.model.Money
import com.shopkmp.sdui.model.Node
import com.shopkmp.sdui.model.ScreenDocument
import com.shopkmp.sdui.model.Space
import com.shopkmp.sdui.model.StatusKind
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.chip.EpsChip
import com.shopkmp.uikit.component.chip.EpsChipState
import com.shopkmp.uikit.component.divider.EpsDivider
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.image.EpsImage
import com.shopkmp.uikit.component.image.EpsImageState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholder
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.loader.EpsLoader
import com.shopkmp.uikit.component.quantity.EpsQuantityStepper
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.shop.EpsCartItem
import com.shopkmp.uikit.component.shop.EpsCartItemState
import com.shopkmp.uikit.component.shop.EpsEmptyState
import com.shopkmp.uikit.component.shop.EpsOrderRow
import com.shopkmp.uikit.component.shop.EpsOrderRowState
import com.shopkmp.uikit.component.shop.EpsProductCard
import com.shopkmp.uikit.component.shop.EpsProductState
import com.shopkmp.uikit.component.shop.EpsProfileField
import com.shopkmp.uikit.component.shop.EpsProfileHeader
import com.shopkmp.uikit.component.shop.EpsProfileHeaderState
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

@Composable
fun SduiScreen(
    document: ScreenDocument,
    fields: Map<String, String> = document.state.fields,
    fieldErrors: Map<String, String> = emptyMap(),
    actionInProgress: Boolean = false,
    businessOrigin: String,
    modifier: Modifier = Modifier,
    leadingContent: (@Composable () -> Unit)? = null,
    onEvent: (SduiEvent) -> Unit,
) {
    val registry = EventRegistry(document, actionInProgress, onEvent)
    val context = RenderContext(fields, fieldErrors, registry, businessOrigin)
    val plan = remember(document) { screenRenderPlan(document.components) }
    LazyColumn(
        modifier = modifier.fillMaxSize().testTag("sdui.vertical"),
        contentPadding = PaddingValues(plan.layout.padding.dp()),
        verticalArrangement = Arrangement.spacedBy(plan.layout.spacing.dp()),
        horizontalAlignment = plan.layout.align.horizontalAlignment(),
    ) {
        if (leadingContent != null) item(key = "local:add-recovery") { leadingContent() }
        items(plan.items, key = { it.lazyKey }) { item ->
            when (item) {
                is RenderItem.Single -> RenderNode(item.node, context)
                is RenderItem.GridRow -> GridRow(item.nodes, context)
            }
        }
    }
}

private data class RenderContext(
    val fields: Map<String, String>, val errors: Map<String, String>, val events: EventRegistry, val origin: String,
)

@Composable
private fun RenderNode(node: Node, c: RenderContext, modifier: Modifier = Modifier) {
    val tagged = modifier.testTag(node.id)
    when (node) {
        is Node.Text -> EpsText(EpsTextState(node.props.text, EpsTextStyle.valueOf(node.props.style.name)), tagged)
        is Node.Price -> EpsText(EpsTextState(Money.display(node.props.amount), EpsTextStyle.Price), tagged)
        is Node.Icon -> SduiIcon(node.props.name, node.props.description, tagged)
        is Node.Image -> {
            val slot = when (node.props.aspect) {
                Aspect.Product -> tagged.fillMaxWidth().height(220.dp)
                Aspect.Thumbnail -> tagged.size(80.dp)
                Aspect.Avatar -> tagged.size(72.dp)
            }
            val url = MediaPolicy.publicUrl(node.props.source, c.origin)
            if (url == null) EpsImagePlaceholder(EpsImagePlaceholderState(if (node.props.aspect == Aspect.Avatar) "Профиль" else node.props.description), slot)
            else EpsImage(EpsImageState(url, node.props.description), slot)
        }
        is Node.Layout -> Layout(node, c, tagged)
        is Node.Button -> EpsButton(EpsButtonState(node.props.label, EpsButtonType.valueOf(node.props.style.name),
            node.props.enabled && c.events.enabled(node.props.actionId), c.events.activate(node.props.actionId)), tagged)
        is Node.Field -> Field(node, c, tagged)
        is Node.ProductCard -> Product(node, c, tagged)
        is Node.ProductGrid -> Column(tagged, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            node.children.chunked(2).forEach { row -> key(row.first().id) { GridRow(row, c) } }
        }
        is Node.CategoryList -> LazyRow(tagged.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(node.props.items, key = { it.id }) { category ->
                ActionChip(category.label, category.selected, c.events.activate(category.actionId))
            }
        }
        is Node.QuantityStepper -> with(node.props) {
            val value = c.fields[fieldId]?.toIntOrNull() ?: value
            Box(tagged) { EpsQuantityStepper(EpsQuantityStepperState(value,
                enabled && c.events.enabled(actionId) && value > min,
                enabled && c.events.enabled(actionId) && value < max,
                { c.events.quantity(actionId, value - 1) }, { c.events.quantity(actionId, value + 1) })) }
            c.errors[fieldId]?.let { EpsErrorMessage(it) }
        }
        is Node.CartItem -> with(node.props) {
            Column(tagged, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                EpsCartItem(EpsCartItemState(name, Money.display(price), if (!available || quantity > stock) "Недостаточно товара · Доступно: $stock" else "В наличии: $stock",
                    EpsImagePlaceholderState(name), EpsCardState(onClick = c.events.activate(openAction)),
                    EpsQuantityStepperState(quantity, c.events.enabled(quantityAction) && quantity > 0,
                        c.events.enabled(quantityAction) && quantity < minOf(stock, 1_000_000),
                        { c.events.quantity(quantityAction, quantity - 1) }, { c.events.quantity(quantityAction, quantity + 1) })))
                EpsButton(EpsButtonState("Удалить", EpsButtonType.Text, c.events.enabled(removeAction), c.events.activate(removeAction)))
            }
        }
        is Node.OrderCard -> with(node.props) {
            Column(tagged) {
                EpsOrderRow(EpsOrderRowState(number, status.name, Money.display(total), deliveryAddress, paymentMethod.name,
                    Color(0xFF0F7A3B), EpsCardState(onClick = c.events.activate(openAction))), Modifier.testTag("${node.id}.open"))
                EpsText(EpsTextState(createdAt, EpsTextStyle.Secondary))
            }
        }
        is Node.ProfileHeader -> with(node.props) {
            Column(tagged) {
                // No authenticated image API exists in UIKit: keep uploaded avatars out of its public image loader.
                val initials = fullName.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.take(2).joinToString("") { it.take(1).uppercase() }
                EpsProfileHeader(EpsProfileHeaderState(initials.ifEmpty { "?" }, fullName, email))
                EpsProfileField("Телефон", phone)
            }
        }
        is Node.Status -> Column(tagged, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            when (node.type) {
                StatusKind.Empty -> EpsEmptyState(node.props.title, node.props.message, EpsCardState())
                StatusKind.Loading -> {
                    Box(Modifier.fillMaxWidth().height(72.dp)) { EpsLoader() }
                    EpsText(EpsTextState("${node.props.title}: ${node.props.message}"))
                }
                StatusKind.Error -> EpsErrorMessage("${node.props.title}: ${node.props.message}")
            }
            node.props.retryAction?.let { EpsButton(EpsButtonState("Повторить", enabled = c.events.enabled(it), onClick = c.events.activate(it))) }
        }
        is Node.Spacer -> EpsSpacer(EpsSpacerState.height(node.props.size.dp()), tagged)
        is Node.Divider -> EpsDivider(EpsDividerState(), tagged)
        is Node.Form -> Column(tagged.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            EpsText(EpsTextState(node.props.title, EpsTextStyle.SectionTitle))
            node.children.forEach { child -> key(child.id) { RenderNode(child, c) } }
            if (node.children.none { it is Node.Button && it.props.actionId == node.props.submitAction }) {
                EpsButton(EpsButtonState("Применить", enabled = c.events.enabled(node.props.submitAction), onClick = c.events.activate(node.props.submitAction)))
            }
        }
        is Node.Unknown -> EpsText(EpsTextState("Этот раздел пока недоступен", EpsTextStyle.Secondary), tagged)
    }
}

@Composable
private fun Field(node: Node.Field, c: RenderContext, modifier: Modifier) = with(node.props) {
    val value = c.fields[fieldId].orEmpty()
    if (input == Input.Select) {
        Column(modifier, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            EpsText(EpsTextState(label))
            LazyRow(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(options, key = { it.value }) { option ->
                    ActionChip(option.label, option.value == value,
                        if (!readOnly && c.events.enabled(changeAction)) ({ c.events.field(changeAction, option.value) }) else null,
                        Modifier.testTag("${node.id}.option.${option.value}"))
                }
            }
            c.errors[fieldId]?.let { EpsErrorMessage(it) }
        }
    } else EpsTextField(EpsTextFieldState(value = value, label = label, error = c.errors[fieldId],
        enabled = !readOnly && c.events.enabled(changeAction),
        keyboardOptions = KeyboardOptions(keyboardType = when (input) {
            Input.Phone -> KeyboardType.Phone
            Input.Number -> KeyboardType.Number
            else -> KeyboardType.Text
        }), onValueChange = { c.events.field(changeAction, it) }), modifier)
}

@Composable
private fun Product(node: Node.ProductCard, c: RenderContext, modifier: Modifier) = with(node.props) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(4.dp)) {
        EpsProductCard(EpsProductState(name, description, Money.display(discountPrice ?: price),
            rating?.let { "$it · $reviewsCount" } ?: "Нет оценок", MediaPolicy.publicUrl(image, c.origin),
            EpsImagePlaceholderState(name), EpsCardState(onClick = c.events.activate(openAction))), Modifier.testTag("${node.id}.open"))
        if (discountPrice != null) EpsText(EpsTextState("Без скидки: ${Money.display(price)}", EpsTextStyle.Secondary))
        EpsText(EpsTextState(description, EpsTextStyle.Secondary))
        EpsText(EpsTextState(rating?.let { "Рейтинг: $it · Отзывов: $reviewsCount" } ?: "Нет оценок", EpsTextStyle.Secondary))
        addAction?.let { EpsButton(EpsButtonState("В корзину", enabled = stock > 0 && c.events.enabled(it), onClick = c.events.activate(it))) }
    }
}

@Composable
private fun GridRow(nodes: List<Node>, c: RenderContext) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        nodes.forEach { node -> key(node.id) { RenderNode(node, c, Modifier.weight(1f)) } }
        if (nodes.size == 1) Spacer(Modifier.weight(1f))
    }
}

@Composable
private fun Layout(node: Node.Layout, c: RenderContext, modifier: Modifier) {
    val padding = modifier.padding(node.props.padding.dp())
    val spacing = node.props.spacing.dp()
    val horizontal = node.props.align.horizontalAlignment()
    when (node.type) {
        LayoutKind.Row -> Row(padding.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(spacing), verticalAlignment = when (node.props.align) {
            Align.Start -> Alignment.Top; Align.Center -> Alignment.CenterVertically; Align.End -> Alignment.Bottom
        }) { node.children.forEach { key(it.id) { RenderNode(it, c, Modifier.weight(1f)) } } }
        LayoutKind.LazyRow -> LazyRow(padding.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(spacing)) {
            items(node.children, key = { it.id }) { RenderNode(it, c, Modifier.width(280.dp)) }
        }
        LayoutKind.Box -> Box(padding.fillMaxWidth(), contentAlignment = when (node.props.align) {
            Align.Start -> Alignment.TopStart; Align.Center -> Alignment.Center; Align.End -> Alignment.BottomEnd
        }) { node.children.forEach { key(it.id) { RenderNode(it, c) } } }
        LayoutKind.Column, LayoutKind.LazyColumn -> Column(padding.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(spacing), horizontalAlignment = horizontal) {
            node.children.forEach { key(it.id) { RenderNode(it, c) } }
        }
    }
}
private fun Space.dp(): Dp = when (this) {
    Space.none -> 0.dp; Space.xs -> 4.dp; Space.s -> 8.dp; Space.m -> 16.dp; Space.l -> 24.dp; Space.xl -> 32.dp
}
private fun Align.horizontalAlignment(): Alignment.Horizontal = when (this) {
    Align.Start -> Alignment.Start
    Align.Center -> Alignment.CenterHorizontally
    Align.End -> Alignment.End
}

@Composable
private fun ActionChip(label: String, selected: Boolean, onClick: (() -> Unit)?, modifier: Modifier = Modifier) {
    // EpsChip's API has no enabled flag. Suppress its child click semantics for disabled actions.
    val disabledSemantics = if (onClick == null) Modifier.alpha(0.38f).clearAndSetSemantics {
        disabled(); text = AnnotatedString(label)
    } else Modifier
    Box(disabledSemantics) { EpsChip(EpsChipState(label, selected, onClick), modifier) }
}
