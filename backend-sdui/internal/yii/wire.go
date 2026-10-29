package yii

// Private HTTP DTOs describe the upstream protocol. Keep these independent of
// consumer models so shape validation cannot silently follow application changes.
type userWire struct {
	ID               string  `json:"id"`
	Email            string  `json:"email"`
	FullName         string  `json:"fullName"`
	Phone            string  `json:"phone"`
	AvatarURL        *string `json:"avatarUrl"`
	DefaultAddressID *string `json:"defaultAddressId"`
	DateOfBirth      *string `json:"dateOfBirth"`
	CreatedAt        string  `json:"createdAt"`
}
type categoryWire struct {
	ID        string  `json:"id"`
	Name      string  `json:"name"`
	ParentID  *string `json:"parentId"`
	IconURL   string  `json:"iconUrl"`
	SortOrder int     `json:"sortOrder"`
}
type productWire struct {
	ID            string   `json:"id"`
	CategoryID    string   `json:"categoryId"`
	Name          string   `json:"name"`
	Description   string   `json:"description"`
	Price         Money    `json:"price"`
	DiscountPrice *Money   `json:"discountPrice"`
	Stock         int      `json:"stock"`
	Rating        *float64 `json:"rating"`
	ImageURLs     []string `json:"imageUrls"`
	IsActive      bool     `json:"isActive"`
	ReviewsCount  int      `json:"reviewsCount"`
}
type pageWire[T any] struct {
	Items      []T `json:"items"`
	Page       int `json:"page"`
	Size       int `json:"size"`
	TotalPages int `json:"totalPages"`
	TotalItems int `json:"totalItems"`
}
type sessionWire struct {
	User   userWire `json:"user"`
	Device struct {
		PinEnabled            bool    `json:"pinEnabled"`
		BiometricEnabled      bool    `json:"biometricEnabled"`
		BiometricCredentialID *string `json:"biometricCredentialId"`
	} `json:"device"`
	CartItemsCount int `json:"cartItemsCount"`
}
type cartItemWire struct {
	ProductID     string  `json:"productId"`
	ProductName   string  `json:"productName"`
	Quantity      int     `json:"quantity"`
	PriceSnapshot Money   `json:"priceSnapshot"`
	ImageURL      *string `json:"imageUrl"`
	IsAvailable   bool    `json:"isAvailable"`
	Stock         int     `json:"stock"`
}
type cartWire struct {
	ID          string         `json:"id"`
	UserID      string         `json:"userId"`
	Items       []cartItemWire `json:"items"`
	TotalAmount Money          `json:"totalAmount"`
	UpdatedAt   string         `json:"updatedAt"`
}
type addressWire struct {
	ID        string  `json:"id"`
	UserID    string  `json:"userId"`
	City      string  `json:"city"`
	Street    string  `json:"street"`
	House     string  `json:"house"`
	Apartment string  `json:"apartment"`
	Comment   *string `json:"comment"`
}
type orderItemWire struct {
	ProductID     string `json:"productId"`
	ProductName   string `json:"productName"`
	Quantity      int    `json:"quantity"`
	PriceSnapshot Money  `json:"priceSnapshot"`
}
type orderWire struct {
	ID                string          `json:"id"`
	Number            int             `json:"number"`
	UserID            string          `json:"userId"`
	Status            string          `json:"status"`
	Items             []orderItemWire `json:"items"`
	TotalAmount       Money           `json:"totalAmount"`
	DeliveryAddressID string          `json:"deliveryAddressId"`
	DeliveryAddress   string          `json:"deliveryAddress"`
	PaymentID         *string         `json:"paymentId"`
	PaymentMethod     string          `json:"paymentMethod"`
	CreatedAt         string          `json:"createdAt"`
	Comment           *string         `json:"comment"`
}
type deviceWire struct {
	DeviceID     string  `json:"deviceId"`
	DeviceName   string  `json:"deviceName"`
	KeyAlgorithm string  `json:"keyAlgorithm,omitempty"`
	CreatedAt    string  `json:"createdAt"`
	LastUsedAt   string  `json:"lastUsedAt"`
	LockedUntil  *string `json:"lockedUntil"`
}

func convertUser(w userWire) User {
	return User{ID: w.ID, Email: w.Email, FullName: w.FullName, Phone: w.Phone, AvatarURL: w.AvatarURL, DefaultAddressID: w.DefaultAddressID, DateOfBirth: w.DateOfBirth, CreatedAt: w.CreatedAt}
}
func convertProduct(w productWire) Product {
	return Product{ID: w.ID, CategoryID: w.CategoryID, Name: w.Name, Description: w.Description, Price: w.Price, DiscountPrice: w.DiscountPrice, Stock: w.Stock, Rating: w.Rating, ImageURLs: append([]string{}, w.ImageURLs...), IsActive: w.IsActive, ReviewsCount: w.ReviewsCount}
}
func convertCart(w cartWire) Cart {
	out := Cart{ID: w.ID, UserID: w.UserID, TotalAmount: w.TotalAmount, UpdatedAt: w.UpdatedAt, Items: make([]CartItem, 0, len(w.Items))}
	for _, v := range w.Items {
		out.Items = append(out.Items, CartItem{ProductID: v.ProductID, ProductName: v.ProductName, Quantity: v.Quantity, PriceSnapshot: v.PriceSnapshot, ImageURL: v.ImageURL, IsAvailable: v.IsAvailable, Stock: v.Stock})
	}
	return out
}
func convertOrder(w orderWire) Order {
	out := Order{ID: w.ID, Number: w.Number, UserID: w.UserID, Status: w.Status, TotalAmount: w.TotalAmount, DeliveryAddressID: w.DeliveryAddressID, DeliveryAddress: w.DeliveryAddress, PaymentID: w.PaymentID, PaymentMethod: w.PaymentMethod, CreatedAt: w.CreatedAt, Comment: w.Comment, Items: make([]OrderItem, 0, len(w.Items))}
	for _, v := range w.Items {
		out.Items = append(out.Items, OrderItem{ProductID: v.ProductID, ProductName: v.ProductName, Quantity: v.Quantity, PriceSnapshot: v.PriceSnapshot})
	}
	return out
}

type addCartWire struct {
	ProductID string `json:"productId"`
	Quantity  int    `json:"quantity"`
}
type profileWire struct {
	FullName string `json:"fullName"`
	Phone    string `json:"phone"`
}
