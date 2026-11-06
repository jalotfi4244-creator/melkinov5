<?php require_once __DIR__ . '/config.php'; require_once __DIR__ . '/db_helpers.php'; ?>
<style>
/* نوار تب ادمین sticky با z-index:8000 روی مودال می‌افتاد */
.modal-overlay {
    z-index: 20000 !important;
}
#adEditModal.modal-overlay,
#adDetailModal.modal-overlay,
#publishComposerModal.modal-overlay {
    z-index: 20000 !important;
}
body:has(.modal-overlay.active) .tabs-container,
body:has(.modal-overlay.active) .admin-body .tabs-container {
    z-index: 1 !important;
}
body:has(.modal-overlay.active) {
    overflow: hidden;
}
</style>
<!-- =========================================================
     DETAIL MODAL
     ========================================================= -->

<div
    class="modal-overlay"
    id="adDetailModal"
>

    <div class="modal-box">

        <div class="modal-header">

            <h3>
                جزئیات کامل آگهی
            </h3>

            <button
                class="modal-close"
                onclick="closeModal('adDetailModal')"
            >
                ✕
            </button>

        </div>


        <div
            id="adDetailContent"
            style="
                display:flex;
                flex-direction:column;
                gap:var(--space-2);
                flex:1 1 auto;
                min-height:0;
            "
        ></div>

    </div>

</div>


<!-- =========================================================
     EDIT MODAL
     ========================================================= -->

<div
    class="modal-overlay"
    id="adEditModal"
>

    <div class="modal-box">

        <div class="modal-header">

            <h3>
                <?= melkinoSvgIcon('edit') ?> ویرایش آگهی
            </h3>

            <button
                class="modal-close"
                onclick="closeModal('adEditModal')"
            >
                ✕
            </button>

        </div>


        <div
            id="adEditContent"
            style="
                display:flex;
                flex-direction:column;
                flex:1 1 auto;
                min-height:0;
            "
        ></div>

    </div>

</div>

<?php // آهنگساز قدیمی «انتشار» حذف شد (راند ۲۵) — به ماژول «انتشار» منتقل شد.
?>
