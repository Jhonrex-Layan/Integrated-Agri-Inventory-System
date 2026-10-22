    </main>
    <footer class="app-footer"><img src="<?= e(url('assets/img/favicon.png')) ?>" alt=""><span><?= e(app_config()['app_title']) ?> &middot; <?= date('Y') ?></span></footer>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1100">
<?php foreach (flash_take() as $f):
    $cls = ['success' => 'success', 'danger' => 'danger', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'][$f['type']] ?? 'info'; ?>
  <div class="toast align-items-center text-bg-<?= e($cls) ?> border-0" role="alert" data-autoshow="1">
    <div class="d-flex">
      <div class="toast-body"><?= e($f['msg']) ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Please confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="confirmMessage">Are you sure?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmOk">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= e(vendor_url('bootstrap.bundle.min.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
