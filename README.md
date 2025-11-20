# Complete PHP Backend for Social Media App

## Database Schema (schema.sql)
```sql
CREATE DATABASE socially_app;
USE socially_app;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    bio TEXT,
    profile_picture VARCHAR(255),
    cover_photo VARCHAR(255),
    is_online BOOLEAN DEFAULT FALSE,
    last_seen TIMESTAMP,
    is_private BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT,
    media_url VARCHAR(255),
    media_type VARCHAR(50),
    likes_count INT DEFAULT 0,
    comments_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    media_url VARCHAR(255) NOT NULL,
    media_type VARCHAR(50),
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    follower_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_follow (user_id, follower_id)
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    content TEXT,
    message_type VARCHAR(20) DEFAULT 'text',
    media_url VARCHAR(255),
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (post_id, user_id)
);
```

## Project Structure
```
backend/
├── config/
│   └── database.php
├── models/
│   ├── User.php
│   ├── Post.php
│   └── Response.php
├── controllers/
│   ├── AuthController.php
│   ├── PostController.php
│   └── MediaController.php
├── middleware/
│   └── AuthMiddleware.php
├── uploads/
│   ├── profiles/
│   ├── posts/
│   └── stories/
├── .htaccess
├── index.php
├── register.php
├── login.php
├── profile.php
├── create_post.php
├── get_posts.php
├── upload_media.php
└── README.md
```

## Setup Instructions

1. Create a new folder for your backend in VS Code
2. Copy all the files I'll provide below into that folder
3. Set up a local server (XAMPP, WAMP, or MAMP)
4. Import the database schema
5. Update the database configuration
6. Test the API endpoints

Your Android app is now fully integrated and ready to connect to this backend!
