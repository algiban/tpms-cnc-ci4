<nav
    class="app-navbar d-flex align-items-center justify-content-between">

    <div>

        <span class="text-secondary small">
            <?= date('l, d F Y') ?>
        </span>

    </div>

    <div class="dropdown">

        <button
            class="btn border-0 d-flex align-items-center gap-3"
            data-bs-toggle="dropdown">

            <div class="text-end d-none d-sm-block">

                <div class="fw-semibold small">
                    <?= esc(session()->get('username')) ?>
                </div>

                <div class="text-secondary" style="font-size: 12px;">
                    <?= ucfirst(esc(session()->get('role'))) ?>
                </div>

            </div>

            <div
                class="rounded-circle bg-primary text-white
                       d-flex align-items-center justify-content-center"
                style="width: 40px; height: 40px;">
                <i class="bi bi-person"></i>
            </div>

        </button>

        <ul class="dropdown-menu dropdown-menu-end border-0 shadow">

            <li>

                <div class="px-3 py-2">

                    <div class="fw-semibold">
                        <?= esc(session()->get('username')) ?>
                    </div>

                    <small class="text-secondary">
                        <?= ucfirst(esc(session()->get('role'))) ?>
                    </small>

                </div>

            </li>

            <li>
                <hr class="dropdown-divider">
            </li>

            <li>

                <form
                    action="<?= site_url('/logout') ?>"
                    method="post">

                    <?= csrf_field() ?>

                    <button
                        type="submit"
                        class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </button>

                </form>

            </li>

        </ul>

    </div>

</nav>