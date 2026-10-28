package com.shopkmp.sdui.renderer

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.background
import androidx.compose.material3.IconButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.shopkmp.sdui.actions.EventRegistry
import com.shopkmp.sdui.model.FormId
import com.shopkmp.sdui.model.Node
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState
import com.shopkmp.ui.theme.AppShapes
import com.shopkmp.ui.theme.ShopTokens

/** Local disclosure state only; all editable data and submissions stay host-controlled. */
@Composable
internal fun SduiForm(
    node: Node.Form,
    fields: Map<String, String>,
    errors: Map<String, String>,
    events: EventRegistry,
    modifier: Modifier,
    render: @Composable (Node) -> Unit,
) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        when {
            node.id == "home.search.form" -> {
                val field = node.children.filterIsInstance<Node.Field>().singleOrNull()
                val button = node.children.filterIsInstance<Node.Button>().singleOrNull()
                if (field != null && button != null) {
                    EpsTextField(EpsTextFieldState(
                        value = fields[field.props.fieldId].orEmpty(), placeholder = "Поиск товаров, категорий…",
                        error = errors[field.props.fieldId], enabled = events.enabled(field.props.changeAction), readOnly = field.props.readOnly,
                        onValueChange = { events.field(field.props.changeAction, it) },
                    ), Modifier.fillMaxWidth().testTag(field.id).semantics { contentDescription = field.props.label }, trailingIcon = {
                        IconButton(onClick = { events.activate(button.props.actionId)?.invoke() },
                            enabled = button.props.enabled && events.enabled(button.props.actionId),
                            modifier = Modifier.testTag(button.id)) { EpsIcon(EpsIconName.Search, button.props.label) }
                    })
                } else node.children.forEach { render(it) }
            }
            node.props.formId == FormId.checkout -> {
                val address = node.children.filterIsInstance<Node.Field>().filter { it.props.fieldId in addressFields }
                val payment = node.children.filterIsInstance<Node.Field>().firstOrNull { it.props.fieldId == "checkout.paymentMethod" }
                val invalidAddress = address.any { errors.containsKey(it.props.fieldId) || it.props.required && fields[it.props.fieldId].isNullOrBlank() }
                var editAddress by rememberSaveable(node.id) { mutableStateOf(invalidAddress) }
                var editPayment by rememberSaveable(node.id) { mutableStateOf(false) }
                // Latch every automatic reveal, including later validation errors. Resolving
                // the final blank/error must not remove focused inputs during editing.
                SideEffect { if (invalidAddress) editAddress = true }
                val paymentValue = fields["checkout.paymentMethod"].orEmpty()
                val invalidPayment = errors.containsKey("checkout.paymentMethod") || payment?.props?.options?.none { it.value == paymentValue } == true
                val summary = address.mapNotNull { fields[it.props.fieldId]?.takeIf(String::isNotBlank) }.joinToString(", ")
                DisclosureSummary("Адрес", summary.ifEmpty { "Укажите адрес доставки" }, "checkout.address.edit", events.enabled(node.props.submitAction)) {
                    if (!invalidAddress) editAddress = !editAddress
                }
                if (editAddress || invalidAddress) address.forEach { render(it) }
                DisclosureSummary("Оплата", payment?.props?.options?.firstOrNull { it.value == paymentValue }?.label ?: "Выберите способ оплаты",
                    "checkout.payment.edit", events.enabled(node.props.submitAction)) { editPayment = !editPayment }
                if (editPayment || invalidPayment) payment?.let { render(it) }
                node.children.filter { it !in address && it != payment }.forEach { render(it) }
            }
            node.props.formId == FormId.orders -> {
                var expanded by rememberSaveable(node.id) { mutableStateOf(false) }
                val field = node.children.filterIsInstance<Node.Field>().firstOrNull()
                val label = field?.props?.options?.firstOrNull { it.value == fields[field.props.fieldId] }?.label ?: "Все"
                DisclosureSummary("Фильтр", label, "orders.filter.edit", events.enabled(node.props.submitAction)) { expanded = !expanded }
                if (expanded || node.children.filterIsInstance<Node.Field>().any { errors.containsKey(it.props.fieldId) }) node.children.forEach { render(it) }
            }
            else -> {
                EpsText(EpsTextState(node.props.title, EpsTextStyle.SectionTitle))
                node.children.forEach { render(it) }
            }
        }
        if (node.children.none { it is Node.Button && it.props.actionId == node.props.submitAction }) {
            EpsButton(EpsButtonState("Применить", enabled = events.enabled(node.props.submitAction), onClick = events.activate(node.props.submitAction)), Modifier.fillMaxWidth())
        }
    }
}

private val addressFields = setOf("checkout.city", "checkout.street", "checkout.house", "checkout.apartment")

@Composable
private fun DisclosureSummary(label: String, value: String, tag: String, enabled: Boolean, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(ShopTokens.Field, AppShapes.field).padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f).padding(vertical = 4.dp)) {
            EpsText(EpsTextState(label, EpsTextStyle.Secondary))
            EpsText(EpsTextState(value))
        }
        EpsButton(EpsButtonState("Изменить", EpsButtonType.Text, enabled, onClick), Modifier.testTag(tag))
    }
}

@Composable
internal fun ProfileAddresses(nodes: List<Node>, defaultSummary: String?, render: @Composable (Node) -> Unit) {
    val additional = nodes.filterNot { it is Node.Text && it.props.text == defaultSummary }
    if (additional.isEmpty()) return
    var expanded by rememberSaveable { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        EpsButton(EpsButtonState(if (expanded) "Скрыть адреса" else "Другие адреса", EpsButtonType.Text, onClick = { expanded = !expanded }), Modifier.testTag("profile.addresses.expand"))
        if (expanded) additional.forEach { render(it) }
    }
}
