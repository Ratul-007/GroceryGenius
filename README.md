# GroceryGenius 🛒

A smart grocery management and meal planning web application built for everyday Bangladeshi households.

## 🌟 Features

- **Pantry Manager** — Track grocery items, quantities, and expiry dates with real-time expiry alerts
- **Recipe Suggestions** — Get recipe ideas based on available pantry ingredients
- **Shopping List** — Auto-generate and manage your shopping list
- **Budget Tracker** — Monitor grocery spending and stay within budget
- **Price Tracker** — Record and compare daily grocery prices with 7-day trend charts

## 👥 Team — 404 Team Not Found

| Name | Role |
|------|------|
| Ratul | Backend Developer (Team Leader) |
| Arnab | Frontend Developer |
| Prabak | UI/UX Designer |

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, JavaScript |
| Backend | PHP 8 |
| Database | MySQL |
| Server | Apache (XAMPP) |

## ⚙️ Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/Ratul-007/GroceryGenius.git
   ```

2. **Move to XAMPP's htdocs folder**
   ```bash
   # Windows
   move GroceryGenius C:\xampp\htdocs\GroceryGenius
   ```

3. **Import the database**
   - Start Apache and MySQL from XAMPP Control Panel
   - Open `http://localhost/phpmyadmin`
   - Create a new database named `grocerygenius`
   - Import `grocerygenius.sql`

4. **Configure database connection**
   - Open `config/db.php`
   - Set your MySQL host, username, and password

5. **Run the app**
   - Visit `http://localhost/GroceryGenius/index.php`

## 📁 Project Structure

```
GroceryGenius/
├── assets/
│   └── css/            # Stylesheets
├── config/
│   └── db.php          # Database connection
├── pages/              # All PHP pages
│   ├── dashboard.php
│   ├── pantry.php
│   ├── recipes.php
│   ├── shopping.php
│   ├── budget.php
│   └── prices.php
├── sql/                # SQL scripts
├── grocerygenius.sql   # Full database dump
└── index.php           # Entry point
```

## 🔮 Future Scope

- **Automated Price Updates** — Integration with TCB (Trading Corporation of Bangladesh) daily commodity price data to automatically update grocery prices without manual entry
- **AI Recipe Recommendations** — Smarter recipe suggestions using machine learning based on pantry contents and user preferences
- **Mobile App** — Native Android/iOS version for on-the-go pantry and shopping list management
- **Multi-user Household** — Share pantry and shopping lists across family members
- **Barcode Scanner** — Scan product barcodes to add items to pantry instantly
- **Price Alert System** — Get notified when a tracked product price rises above a threshold

## 📄 License

This project was developed as an academic project for the Software Engineering course at Chittagong Independent University (Summer 2026).