package yii

import "shopkmp/backend-sdui/internal/application/model"

// Compatibility names for existing adapter callers. Decoding uses private wire
// DTOs and explicit conversion, not these consumer-owned read models.
type Money = model.Money
type User = model.User
type Category = model.Category
type Product = model.Product
type Page[T any] = model.Page[T]
type Session = model.Session
type CartItem = model.CartItem
type Cart = model.Cart
type Address = model.Address
type OrderItem = model.OrderItem
type Order = model.Order
type Device = model.Device
type AddCartInput = model.AddCartInput
type ProfileInput = model.ProfileInput

func ValidMoney(s string) bool { return model.ValidMoney(s) }
