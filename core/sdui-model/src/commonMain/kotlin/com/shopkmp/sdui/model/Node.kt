package com.shopkmp.sdui.model

import kotlinx.serialization.DeserializationStrategy
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonContentPolymorphicSerializer
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

@Serializable(with = NodeSerializer::class)
sealed interface Node {
    val id: String
    @Serializable data class Text(override val id: String, val props: TextProps) : Node
    @Serializable data class Image(override val id: String, val props: ImageProps) : Node
    @Serializable data class Icon(override val id: String, val props: IconProps) : Node
    @Serializable data class Layout(override val id: String, val type: LayoutKind, val props: LayoutProps, val children: List<Node> = emptyList()) : Node
    @Serializable data class Button(override val id: String, val props: ButtonProps) : Node
    @Serializable data class Field(override val id: String, val type: FieldKind, val props: FieldProps) : Node
    @Serializable data class ProductCard(override val id: String, val props: ProductProps) : Node
    @Serializable data class ProductGrid(override val id: String, val props: GridProps, val children: List<Node> = emptyList()) : Node
    @Serializable data class CategoryList(override val id: String, val props: CategoriesProps) : Node
    @Serializable data class Price(override val id: String, val props: PriceProps) : Node
    @Serializable data class QuantityStepper(override val id: String, val props: StepperProps) : Node
    @Serializable data class CartItem(override val id: String, val props: CartProps) : Node
    @Serializable data class OrderCard(override val id: String, val props: OrderProps) : Node
    @Serializable data class ProfileHeader(override val id: String, val props: ProfileProps) : Node
    @Serializable data class Status(override val id: String, val type: StatusKind, val props: StatusProps) : Node
    @Serializable data class Spacer(override val id: String, val props: SpacerProps) : Node
    @Serializable data class Divider(override val id: String) : Node
    @Serializable data class Form(override val id: String, val props: FormProps, val children: List<Node> = emptyList()) : Node
    @Serializable data class Unknown(override val id: String) : Node
}
fun Node.children(): List<Node> = when (this) {
    is Node.Layout -> children
    is Node.ProductGrid -> children
    is Node.Form -> children
    else -> emptyList()
}
fun Node.descendants(): List<Node> = children().flatMap { listOf(it) + it.descendants() }

@Serializable enum class TextStyle { Body, Secondary, Title, SectionTitle, ScreenTitle, HeroTitle, Price, Error, Avatar }
@Serializable enum class ButtonStyle { Primary, Secondary, Text }
@Serializable enum class Space { none, xs, s, m, l, xl }
@Serializable enum class Align { Start, Center, End }
@Serializable enum class Aspect { Product, Thumbnail, Avatar }
@Serializable enum class IconName { Search, Cart, Profile, Back, Check, Device, Warning }
@Serializable enum class LayoutKind { Column, Row, Box, LazyColumn, LazyRow }
@Serializable enum class FieldKind { TextField, SearchField, FormField }
@Serializable enum class Input { Text, Number, Phone, Select }
@Serializable enum class StatusKind { Empty, Loading, Error }
@Serializable enum class FormId { catalog, orders, profile, checkout }
@Serializable data class TextProps(val text: String, val style: TextStyle)
@Serializable data class ImageProps(val source: String, val description: String, val aspect: Aspect)
@Serializable data class IconProps(val name: IconName, val description: String)
@Serializable data class LayoutProps(val spacing: Space, val padding: Space, val align: Align)
@Serializable data class ButtonProps(val label: String, val actionId: String, val style: ButtonStyle, val enabled: Boolean)
@Serializable data class Option(val value: String, val label: String)
@Serializable data class FieldProps(
    val fieldId: String, val label: String, val input: Input, val required: Boolean, val readOnly: Boolean,
    val maxLength: Int, val changeAction: String, val maxUtf8Bytes: Int? = null, val options: List<Option> = emptyList(),
)
@Serializable data class ProductProps(
    val productId: String, val name: String, val description: String, val descriptionTruncated: Boolean,
    val price: String, val discountPrice: String?, val image: String?, val stock: Int,
    val rating: Double?, val reviewsCount: Int, val openAction: String, val addAction: String? = null,
)
@Serializable data class GridProps(val columns: Int)
@Serializable data class Category(val id: String, val label: String, val image: String?, val actionId: String, val selected: Boolean)
@Serializable data class CategoriesProps(val items: List<Category>)
@Serializable data class PriceProps(val amount: String, val currency: String)
@Serializable data class StepperProps(val fieldId: String, val value: Int, val min: Int, val max: Int, val actionId: String, val enabled: Boolean)
@Serializable data class CartProps(
    val productId: String, val name: String, val quantity: Int, val price: String, val image: String?,
    val available: Boolean, val stock: Int, val openAction: String, val quantityAction: String, val removeAction: String,
)
@Serializable data class OrderProps(
    val orderId: String, val number: Int, val status: OrderStatus, val total: String, val deliveryAddress: String,
    val paymentMethod: PaymentMethod, val createdAt: String, val openAction: String,
)
@Serializable data class ProfileProps(val fullName: String, val email: String, val phone: String, val avatar: String?)
@Serializable data class StatusProps(val title: String, val message: String, val retryAction: String? = null)
@Serializable data class SpacerProps(val size: Space)
@Serializable data class FormProps(val formId: FormId, val title: String, val submitAction: String)

object NodeSerializer : JsonContentPolymorphicSerializer<Node>(Node::class) {
    override fun selectDeserializer(element: JsonElement): DeserializationStrategy<Node> = when (element.jsonObject["type"]?.jsonPrimitive?.content) {
        "Text" -> Node.Text.serializer()
        "Image" -> Node.Image.serializer()
        "Icon" -> Node.Icon.serializer()
        "Column", "Row", "Box", "LazyColumn", "LazyRow" -> Node.Layout.serializer()
        "Button" -> Node.Button.serializer()
        "TextField", "SearchField", "FormField" -> Node.Field.serializer()
        "ProductCard" -> Node.ProductCard.serializer()
        "ProductGrid" -> Node.ProductGrid.serializer()
        "CategoryList" -> Node.CategoryList.serializer()
        "Price" -> Node.Price.serializer()
        "QuantityStepper" -> Node.QuantityStepper.serializer()
        "CartItem" -> Node.CartItem.serializer()
        "OrderCard" -> Node.OrderCard.serializer()
        "ProfileHeader" -> Node.ProfileHeader.serializer()
        "Empty", "Loading", "Error" -> Node.Status.serializer()
        "Spacer" -> Node.Spacer.serializer()
        "Divider" -> Node.Divider.serializer()
        "Form" -> Node.Form.serializer()
        else -> Node.Unknown.serializer()
    }
}
