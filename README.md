# Movie Discovery & Social Platform

A Web Programming 2 team project: a movie discovery and social platform built with React, Laravel, and MySQL.

## Project goals

- Discover movies and view movie details.
- Manage ratings, reviews, watchlists, and custom lists.
- Follow users and view social activity.
- Plan movie nights with invitations and voting.
- Support real-time discussion rooms.
- Provide recommendations and review sentiment analysis.
- Demonstrate Laravel MVC, API integration, Agile planning, and (if confirmed by the instructor) independently running microservices.

## Architecture (planned)

- `frontend/`: React single-page application, created with Vite.
- `backend/`: Laravel REST API and core business logic.
- `services/recommendations/`: proposed independent recommendation HTTP service.
- `services/sentiment/`: proposed independent review-sentiment HTTP service.
- `docs/`: architecture, ERD, API contracts, decisions, and meeting notes.

The folders under `services/` are placeholders only. Do not implement separate services until the team confirms the required microservice definition with the instructor. Laravel classes that call third-party APIs are useful service classes, but they are not independent microservices.

## Planned technology stack

- Frontend: React + Vite + JavaScript + React Router + Axios
- Styling: Bootstrap (chosen for a beginner-friendly starting point; avoid mixing styling frameworks)
- Backend: Laravel REST API
- Database: MySQL
- Authentication: Laravel Sanctum; Laravel Socialite if Google login is required
- Real-time: Laravel Reverb + Laravel Echo, if required and time permits
- Testing: Laravel feature tests, frontend tests if time permits, and Postman for API exploration
- Planning: Azure Boards
- Code hosting: GitHub
- Azure Boards is linked to GitHub for work-item traceability.

## Repository structure

```text
web-movie-platform/
├── frontend/                 # React application (Vite)
├── backend/                  # Laravel API
├── services/
│   ├── recommendations/      # Future independent HTTP service
│   └── sentiment/            # Future independent HTTP service
├── docs/
│   ├── architecture/
│   ├── api/
│   ├── decisions/
│   └── meetings/
├── .github/
│   └── pull_request_template.md
├── .gitignore
└── README.md
```

Generated framework files belong inside `frontend/` and `backend/`; do not recreate their internal framework structure manually. A `.gitkeep` file is used only to make an otherwise empty directory visible in Git.

## Prerequisites

Install the following on each developer's machine:

- Git
- Node.js LTS and npm
- PHP version required by the chosen Laravel release
- Composer
- MySQL
- A code editor (for example, Visual Studio Code)
- Postman or an equivalent API client

Check installation:

```bash
git --version
node --version
npm --version
php --version
composer --version
```

## First-time setup

1. Clone the repository:

   ```bash
   git clone https://github.com/RGH-1/web-movie-platform.git
   cd web-movie-platform
   ```

2. Create the React app in `frontend/` using Vite:

   ```bash
   npm create vite@latest frontend -- --template react
   cd frontend
   npm install
   npm install react-router-dom axios bootstrap
   ```

   Vite is the development server and build tool used to run and package the React app. It is not an alternative to React.

3. Create the Laravel project in `backend/` from the repository root:

   ```bash
   composer create-project laravel/laravel backend
   ```

4. Follow the team setup notes in `docs/` before configuring `.env`. Never commit `.env`, API keys, passwords, OAuth secrets, or other credentials.

5. Database setup and API integration instructions will be added after the team agrees on the ERD and API contract.

Do not run the frontend or backend creation commands if a teammate has already initialized that directory. Coordinate first to avoid overwriting work.

## Branching workflow

Use `dev` as the stable branch. Do not push directly to `main` or `dev`.

Branch naming:

- `feature/<work-item-id>-short-description`
- `fix/<work-item-id>-short-description`
- `docs/<work-item-id>-short-description`
- `test/<work-item-id>-short-description`

Example:

```bash
git switch dev
git pull origin dev
git switch -c feature/123-tmdb-search
```

Make small, focused commits. Use imperative commit messages:

```bash
git add frontend/
git commit -m "feature: add initial movie search page"
git push -u origin feature/123-tmdb-search
```


Resolve conflicts carefully, run relevant tests, then push the branch. Open a GitHub Pull Request into `main`, link the Azure Boards work item, and request at least one teammate's review (Optional). Merge only after approval and checks pass. Delete the feature branch after merging.

Avoid force-pushing shared branches. Never commit secrets, `.env` files, `node_modules/`, `vendor/`, build outputs, or local database dumps.

## Azure Boards integration

Azure Boards is used for Epics, Features, User Stories, Tasks, Bugs, sprint planning, and progress tracking. GitHub hosts the code.

1. Create an Azure DevOps organization and project.
2. In Azure DevOps, open **Project settings → GitHub connections**.
3. Connect the GitHub account/organization and select this repository. A repository admin may need to authorize the Azure Boards GitHub app.
4. Create work items and record their IDs.
5. Include `AB#<ID>` in a commit message or pull request description to link the code activity to the work item. Example: `AB#123 Add movie search page`.
6. Confirm the GitHub commit/PR appears in the work item's Development section.

Example PR title:

`AB#123 Add TMDB movie search page`

Use the same Azure Boards work item for the branch, commits, and PR whenever possible. Do not close a work item merely because a branch exists; move it to Done only after the acceptance criteria are met and the change is merged.

## Pull request checklist

- [ ] Linked Azure Boards work item (`AB#<ID>`)
- [ ] Scope is focused and matches the work item
- [ ] No secrets or `.env` files included
- [ ] Relevant tests/manual checks completed
- [ ] UI screenshots added when useful
- [ ] At least one teammate approved
- [ ] Conflicts resolved and branch updated
- [ ] Documentation updated if behavior or setup changed

## Team definition of Done

A work item is Done when its acceptance criteria are met, the change is integrated, relevant tests/manual checks pass, a teammate has reviewed it, and the work item is updated in Azure Boards.

## Project status

This repository is an initial scaffold. The architecture, ERD, API contracts, implementation, and service boundaries must be agreed by the team and validated against the course rubric before feature development.
