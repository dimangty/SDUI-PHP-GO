package sdui

import (
	"fmt"
	"regexp"
	"shopkmp/backend-sdui/internal/domain"
	"strings"
	"unicode/utf8"
)

var mediaPattern = regexp.MustCompile(`^/(images/products|icons|uploads/users)/[A-Za-z0-9/_.-]+$`)

func Media(s string) *string {
	if !mediaPattern.MatchString(s) || strings.Contains(s, "..") || strings.Contains(s, "//") {
		return nil
	}
	return &s
}
func AllowedField(s string) bool {
	switch s {
	case "catalog.q", "catalog.categoryId", "catalog.sort", "catalog.priceMin", "catalog.priceMax", "catalog.page", "catalog.size", "orders.status", "orders.page", "orders.size", "product.quantity", "checkout.city", "checkout.street", "checkout.house", "checkout.apartment", "checkout.paymentMethod", "profile.fullName", "profile.phone":
		return true
	}
	return false
}
func oneOf(value string, values ...string) bool {
	for _, v := range values {
		if v == value {
			return true
		}
	}
	return false
}
func validateProps(n Node) error {
	valid := false
	switch p := n.Props.(type) {
	case TextProps:
		valid = n.Type == "Text" && oneOf(p.Style, "Body", "Secondary", "Title", "SectionTitle", "ScreenTitle", "HeroTitle", "Price", "Error", "Avatar")
	case ImageProps:
		valid = n.Type == "Image" && Media(p.Source) != nil && oneOf(p.Aspect, "Product", "Thumbnail", "Avatar")
	case IconProps:
		valid = n.Type == "Icon" && oneOf(p.Name, "Search", "Cart", "Profile", "Back", "Check", "Device", "Warning")
	case LayoutProps:
		valid = oneOf(n.Type, "Column", "Row", "Box", "LazyColumn", "LazyRow") && oneOf(p.Spacing, "none", "xs", "s", "m", "l", "xl") && oneOf(p.Padding, "none", "xs", "s", "m", "l", "xl") && oneOf(p.Align, "Start", "Center", "End")
	case ButtonProps:
		valid = n.Type == "Button" && p.ActionID != "" && oneOf(p.Style, "Primary", "Secondary", "Text")
	case FieldProps:
		valid = oneOf(n.Type, "TextField", "SearchField", "FormField") && AllowedField(p.FieldID) && oneOf(p.Input, "Text", "Number", "Phone", "Select") && p.MaxLength >= 1 && p.MaxLength <= 2000 && p.ChangeAction != "" && len(p.Options) <= 500
		valid = valid && ((p.FieldID == "catalog.q" && p.MaxUTF8Bytes == 200) || (p.FieldID != "catalog.q" && p.MaxUTF8Bytes == 0))
	case ProductProps:
		valid = n.Type == "ProductCard" && domain.ValidID(p.ProductID) && domain.ValidMoney(string(p.Price)) && (p.DiscountPrice == nil || domain.ValidMoney(string(*p.DiscountPrice))) && (p.Image == nil || Media(*p.Image) != nil) && p.OpenAction != "" && clientInt(p.Stock) && clientInt(p.ReviewsCount) && (p.Rating == nil || *p.Rating >= 1 && *p.Rating <= 5)
		valid = valid && utf8.RuneCountInString(p.Description) <= MaxDescriptionPreview
	case GridProps:
		valid = n.Type == "ProductGrid" && p.Columns == 2
	case CategoriesProps:
		valid = n.Type == "CategoryList" && len(p.Items) <= MaxCategoryPageSize
		for _, c := range p.Items {
			valid = valid && domain.ValidID(c.ID) && c.ActionID != "" && (c.Image == nil || Media(*c.Image) != nil)
		}
	case PriceProps:
		valid = n.Type == "Price" && domain.ValidMoney(string(p.Amount)) && p.Currency == "RUB"
	case StepperProps:
		valid = n.Type == "QuantityStepper" && AllowedField(p.FieldID) && p.Min >= 0 && p.Max >= p.Min && p.Max <= 1000000 && p.Value >= p.Min && p.Value <= p.Max && p.ActionID != ""
	case CartItemProps:
		valid = n.Type == "CartItem" && domain.ValidID(p.ProductID) && domain.ValidMoney(string(p.Price)) && p.Quantity >= 1 && p.Quantity <= 1000000 && clientInt(p.Stock) && (p.Image == nil || Media(*p.Image) != nil) && p.OpenAction != "" && p.QuantityAction != "" && p.RemoveAction != ""
	case OrderProps:
		valid = n.Type == "OrderCard" && domain.ValidID(p.OrderID) && domain.ValidMoney(string(p.Total)) && p.Number >= 1 && clientInt(p.Number) && validStatus(p.Status) && oneOf(p.PaymentMethod, "CARD", "SBP", "ON_DELIVERY") && p.OpenAction != ""
	case ProfileProps:
		valid = n.Type == "ProfileHeader" && (p.Avatar == nil || Media(*p.Avatar) != nil)
	case StatusProps:
		valid = oneOf(n.Type, "Empty", "Loading", "Error")
	case SpacerProps:
		valid = n.Type == "Spacer" && oneOf(p.Size, "xs", "s", "m", "l", "xl")
	case DividerProps:
		valid = n.Type == "Divider"
	case FormProps:
		valid = n.Type == "Form" && oneOf(p.FormID, "catalog", "orders", "checkout", "profile") && p.SubmitAction != ""
	}
	if !valid {
		return fmt.Errorf("invalid %s props", n.Type)
	}
	return nil
}
func validStatus(s string) bool {
	return oneOf(s, "CREATED", "PAID", "ASSEMBLING", "DELIVERING", "COMPLETED", "CANCELLED")
}
func references(p Props) []string {
	switch v := p.(type) {
	case ButtonProps:
		return []string{v.ActionID}
	case FieldProps:
		return []string{v.ChangeAction}
	case ProductProps:
		return []string{v.OpenAction, v.AddAction}
	case CategoriesProps:
		r := []string{}
		for _, c := range v.Items {
			r = append(r, c.ActionID)
		}
		return r
	case StepperProps:
		return []string{v.ActionID}
	case CartItemProps:
		return []string{v.OpenAction, v.QuantityAction, v.RemoveAction}
	case OrderProps:
		return []string{v.OpenAction}
	case StatusProps:
		return []string{v.RetryAction}
	case FormProps:
		return []string{v.SubmitAction}
	}
	return nil
}
