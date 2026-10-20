// Package yii is the BFF's sole business-data boundary. It has no persistence.
package yii

import (
	"encoding/json"
	"errors"
	"regexp"
)

// Money never passes through floating point, even while decoding JSON.
type Money string

var moneyPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,9})\.[0-9]{2}$`)

func ValidMoney(s string) bool { return moneyPattern.MatchString(s) }
func (m *Money) UnmarshalJSON(b []byte) error {
	var s string
	if err := json.Unmarshal(b, &s); err != nil || !ValidMoney(s) {
		return errors.New("invalid decimal-string money")
	}
	*m = Money(s)
	return nil
}

type User struct {
	ID               string  `json:"id"`
	Email            string  `json:"email"`
	FullName         string  `json:"fullName"`
	Phone            string  `json:"phone"`
	AvatarURL        *string `json:"avatarUrl"`
	DefaultAddressID *string `json:"defaultAddressId"`
	DateOfBirth      *string `json:"dateOfBirth"`
	CreatedAt        string  `json:"createdAt"`
}
type Category struct {
	ID        string  `json:"id"`
	Name      string  `json:"name"`
	ParentID  *string `json:"parentId"`
	IconURL   string  `json:"iconUrl"`
	SortOrder int     `json:"sortOrder"`
}
type Product struct {
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
type Page[T any] struct {
	Items      []T `json:"items"`
	Page       int `json:"page"`
	Size       int `json:"size"`
	TotalPages int `json:"totalPages"`
	TotalItems int `json:"totalItems"`
}
type Session struct {
	User   User `json:"user"`
	Device struct {
		PinEnabled            bool    `json:"pinEnabled"`
		BiometricEnabled      bool    `json:"biometricEnabled"`
		BiometricCredentialID *string `json:"biometricCredentialId"`
	} `json:"device"`
	CartItemsCount int `json:"cartItemsCount"`
}
type CartItem struct {
	ProductID     string  `json:"productId"`
	ProductName   string  `json:"productName"`
	Quantity      int     `json:"quantity"`
	PriceSnapshot Money   `json:"priceSnapshot"`
	ImageURL      *string `json:"imageUrl"`
	IsAvailable   bool    `json:"isAvailable"`
	Stock         int     `json:"stock"`
}
type Cart struct {
	ID          string     `json:"id"`
	UserID      string     `json:"userId"`
	Items       []CartItem `json:"items"`
	TotalAmount Money      `json:"totalAmount"`
	UpdatedAt   string     `json:"updatedAt"`
}
type Address struct {
	ID        string  `json:"id"`
	UserID    string  `json:"userId"`
	City      string  `json:"city"`
	Street    string  `json:"street"`
	House     string  `json:"house"`
	Apartment string  `json:"apartment"`
	Comment   *string `json:"comment"`
}
type OrderItem struct {
	ProductID     string `json:"productId"`
	ProductName   string `json:"productName"`
	Quantity      int    `json:"quantity"`
	PriceSnapshot Money  `json:"priceSnapshot"`
}
type Order struct {
	ID                string      `json:"id"`
	Number            int         `json:"number"`
	UserID            string      `json:"userId"`
	Status            string      `json:"status"`
	Items             []OrderItem `json:"items"`
	TotalAmount       Money       `json:"totalAmount"`
	DeliveryAddressID string      `json:"deliveryAddressId"`
	DeliveryAddress   string      `json:"deliveryAddress"`
	PaymentID         *string     `json:"paymentId"`
	PaymentMethod     string      `json:"paymentMethod"`
	CreatedAt         string      `json:"createdAt"`
	Comment           *string     `json:"comment"`
}
type Device struct {
	DeviceID     string  `json:"deviceId"`
	DeviceName   string  `json:"deviceName"`
	KeyAlgorithm string  `json:"keyAlgorithm,omitempty"`
	CreatedAt    string  `json:"createdAt"`
	LastUsedAt   string  `json:"lastUsedAt"`
	LockedUntil  *string `json:"lockedUntil"`
}
type AddCartInput struct {
	ProductID string `json:"productId"`
	Quantity  int    `json:"quantity"`
}
type ProfileInput struct {
	FullName string `json:"fullName"`
	Phone    string `json:"phone"`
}
