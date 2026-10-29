# Development Setup Guide

## Prerequisites

Before running the project locally, install:

* Git
* Node.js and npm
* PHP compatible with the Laravel version used by the project
* Composer
* MySQL

## 1. Clone the Repository

Clone the `web-movie-platform` repository and navigate into it.

## 2. Configure the Laravel Backend

Navigate to the `backend` directory and install the PHP dependencies:

```bash
composer install
```

Create a local environment file by copying `.env.example` to `.env`. On Windows Command Prompt, use:

```bat
copy .env.example .env
```

On macOS, Linux, or Git Bash, use:

```bash
cp .env.example .env
```

Generate the application key if one has not already been generated for the local environment:

```bash
php artisan key:generate
```

## 3. Configure MySQL

Make sure MySQL is running and that you have access to a database for the project.

Update `backend/.env` with your local database settings:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=web_movie_platform
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Replace the example values with your actual local configuration.

Each developer should keep their own database credentials in their local `.env` file. Never commit that file.

## 4. Verify the Database Connection

From the `backend` directory, run:

```bash
php artisan config:clear
php artisan migrate:status
```

The migration status command should display the database migration status if the connection is configured correctly.

## 5. Start the Backend

From the `backend` directory, run:

```bash
php artisan serve
```

Laravel will display the local address where the application is running.

## 6. Start the Frontend

Open another terminal and navigate to the `frontend` directory:

```bash
npm install
npm run dev
```

Vite will display the local address of the React application.

## 7. Frontend API Configuration

The React frontend uses Vite environment variables to configure the Laravel API base URL.

1. Navigate to the `frontend` directory.
2. Create a `.env.local` file if one does not already exist.
3. Add the following configuration:

```dotenv
VITE_API_BASE_URL=http://127.0.0.1:8000/api
```

4. Start the Laravel backend and React development server.
5. Open the React application and verify that the backend API status reports a successful connection.

Do not commit `.env.local`. Use `.env.example` as the template for documenting required environment variables.

## Important Notes

* Do not commit `.env` files, passwords, API keys, or other secrets.
* Commit `composer.lock` and `package-lock.json` so the team can install consistent dependency versions.
* When dependencies change, install them using the appropriate Composer or npm commands.
* If you encounter environment-specific problems, document the solution so the rest of the team can benefit from it.
