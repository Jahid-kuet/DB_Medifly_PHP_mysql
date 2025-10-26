# 🚀 QUICK START GUIDE

## ✅ Database & Users Created!

### 🔑 Login Credentials

| Role | Username | Password |
|------|----------|----------|
| **Admin** | `admin` | `admin123` |
| **Hospital** | `hospital` | `hospital123` |
| **Operator** | `operator` | `operator123` |

---

## 🌐 Access URLs

- **Login:** http://localhost/DB_PHP/auth/login.php
- **Admin Dashboard:** http://localhost/DB_PHP/admin/dashboard.php
- **Hospital Dashboard:** http://localhost/DB_PHP/hospital/dashboard.php
- **Operator Dashboard:** http://localhost/DB_PHP/operator/dashboard.php

---

## 📊 Sample Data Loaded

✅ **5 Hospitals** in Khulna
✅ **10 Medical Supplies** (Blood, Oxygen, Medicine, PPE, etc.)
✅ **5 Drones** (DJI, Wingcopter, Zipline models)
✅ **3 Users** (Admin, Hospital, Operator)

---

## 🔄 Complete Workflow Test

### 1️⃣ Hospital Creates Request
```
Login: hospital / hospital123
→ Go to "My Requests"
→ Select supply: "Emergency Medicine Kit"
→ Quantity: 2
→ Click on map (Khulna area)
→ Add note (optional)
→ Submit
```

### 2️⃣ Admin Approves & Sets Payment
```
Login: admin / admin123
→ Go to "Requests"
→ Click "Manage" on pending request
→ Status: "approved"
→ Payment Amount: 1500.00
→ Assign Operator: "Drone Operator"
→ Assign Drone: "DJI Matrice 300 RTK"
→ Save
```

### 3️⃣ Admin Confirms Payment
```
Still as admin
→ Go to "Payments" menu
→ Find request in yellow "Pending Payments" section
→ Click "Confirm Payment"
→ Amount: 1500.00
→ Method: bKash
→ Transaction ID: TRX123456 (optional)
→ Submit
```

### 4️⃣ Operator Delivers
```
Login: operator / operator123
→ Go to "Assigned Deliveries"
→ Row is now enabled (not grayed)
→ Click 📍 to view map location
→ Click ✏️ to update status
→ Status: "in-transit"
→ Note: "On the way"
→ Save

Later:
→ Update status: "delivered"
→ Note: "Delivered successfully"
→ Save (drone becomes available again)
```

---

## 🗺️ Google Maps

**API Key:** AIzaSyDM3WrCKwVBIkBiyCBUgV6Ri1ro_oQ4kTg
**Center:** Khulna, Bangladesh (22.8456°N, 89.5403°E)

---

## 🎨 Features

✅ Modern responsive UI with animations
✅ Payment workflow (unpaid → paid)
✅ Google Maps integration
✅ Role-based access control
✅ Status tracking & logs
✅ Operator blocked until payment confirmed

---

## 🛠️ Troubleshooting

### Re-import Database
```cmd
C:\xampp\mysql\bin\mysql.exe -u root -e "DROP DATABASE IF EXISTS mediflydb_php; CREATE DATABASE mediflydb_php;"
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root mediflydb_php < C:\xampp\htdocs\DB_PHP\database.sql"
```

### Reset User Password
```cmd
C:\xampp\php\php.exe -r "echo password_hash('NewPass123', PASSWORD_DEFAULT);"
```
Then in phpMyAdmin:
```sql
UPDATE Users SET password = 'PASTE_HASH' WHERE username = 'admin';
```

---

## 🎯 Quick Commands

```cmd
# Start XAMPP
C:\xampp\xampp-control.exe

# Open App
start http://localhost/DB_PHP/auth/login.php

# Check MySQL
C:\xampp\mysql\bin\mysql.exe -u root mediflydb_php -e "SHOW TABLES;"

# View Users
C:\xampp\mysql\bin\mysql.exe -u root mediflydb_php -e "SELECT * FROM Users;"
```

---

## ✅ ALL FIXED!

- ✅ SQL syntax error in admin/requests.php → **FIXED** (changed to double quotes)
- ✅ Payments table missing → **FIXED** (database re-imported)
- ✅ payment_status column missing → **FIXED** (database re-imported)
- ✅ All users created with correct hashes
- ✅ Sample data loaded (hospitals, supplies, drones)

---

**Ready to Test!** 🚀

Login now: http://localhost/DB_PHP/auth/login.php
