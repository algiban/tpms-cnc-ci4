<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>Login - TPMS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left,
                    rgba(37, 99, 235, .15),
                    transparent 35%),
                #f8fafc;
        }

        .login-container {
            min-height: 100vh;
        }

        .login-card {
            width: 100%;
            max-width: 430px;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .08);
        }

        .logo-box {
            width: 54px;
            height: 54px;
            background: #2563eb;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            font-size: 25px;
        }

        .form-control {
            min-height: 52px;
            border-radius: 12px;
            border-color: #dbe2ea;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10);
        }

        .input-group-text {
            border-radius: 12px 0 0 12px;
            background: #fff;
            border-color: #dbe2ea;
        }

        .btn-login {
            min-height: 52px;
            border-radius: 12px;
            font-weight: 600;
        }

        .monitor-link {
            color: #64748b;
            text-decoration: none;
        }

        .monitor-link:hover {
            color: #2563eb;
        }
    </style>

</head>

<body>

    <div
        class="container login-container d-flex align-items-center justify-content-center py-5">

        <div class="login-card bg-white p-4 p-md-5">

            <div class="mb-5">

                <div class="logo-box mb-4">
                    <i class="bi bi-cpu"></i>
                </div>

                <h2 class="fw-bold mb-2">
                    Welcome back
                </h2>

                <p class="text-secondary mb-0">
                    Sign in to access your TPMS dashboard.
                </p>

            </div>

            <?php if (session()->getFlashdata('success')): ?>

                <div class="alert alert-success">
                    <?= esc(session()->getFlashdata('success')) ?>
                </div>

            <?php endif; ?>

            <?php if (session()->getFlashdata('error')): ?>

                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i>

                    <?= esc(session()->getFlashdata('error')) ?>
                </div>

            <?php endif; ?>

            <?php $errors = session()->getFlashdata('errors') ?? []; ?>

            <form
                method="post"
                action="<?= site_url('/login') ?>">

                <?= csrf_field() ?>

                <div class="mb-3">

                    <label class="form-label fw-medium">
                        Username
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-person text-secondary"></i>
                        </span>

                        <input
                            type="text"
                            name="username"
                            value="<?= old('username') ?>"
                            class="form-control
                        <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                            placeholder="Enter username"
                            autofocus>

                    </div>

                    <?php if (isset($errors['username'])): ?>

                        <small class="text-danger">
                            <?= esc($errors['username']) ?>
                        </small>

                    <?php endif; ?>

                </div>

                <div class="mb-4">

                    <label class="form-label fw-medium">
                        Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock text-secondary"></i>
                        </span>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control
                        <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                            placeholder="Enter password">

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="togglePassword">
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                    <?php if (isset($errors['password'])): ?>

                        <small class="text-danger">
                            <?= esc($errors['password']) ?>
                        </small>

                    <?php endif; ?>

                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-login w-100">
                    Sign In

                    <i class="bi bi-arrow-right ms-2"></i>
                </button>

            </form>

            <div class="text-center mt-4">

                <a
                    href="<?= site_url('/monitoring') ?>"
                    class="monitor-link small">
                    <i class="bi bi-display me-1"></i>

                    Open Production Monitoring
                </a>

            </div>

        </div>

    </div>

    <script>
        document
            .getElementById('togglePassword')
            .addEventListener('click', function() {

                const input = document.getElementById('password');

                const icon = this.querySelector('i');

                if (input.type === 'password') {

                    input.type = 'text';

                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');

                } else {

                    input.type = 'password';

                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');

                }

            });
    </script>

</body>

</html>