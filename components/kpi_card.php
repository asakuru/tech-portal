<?php
/**
 * component: kpi_card
 * @param string $label
 * @param string $value
 * @param string $sub (optional)
 * @param string $class (optional, e.g. positive, negative)
 * @param string $style (optional)
 * @param string $onclick (optional)
 */
?>
<div class="kpi-card" style="<?= $style ?? '' ?> <?= isset($onclick) ? 'cursor:pointer;' : '' ?>" <?= isset($onclick) ? "onclick=\"$onclick\"" : "" ?>>
    <div class="kpi-label"><?= htmlspecialchars($label) ?></div>
    <div class="kpi-value <?= $class ?? '' ?>"><?= $value ?></div>
    <?php if (isset($sub) && $sub !== ''): ?>
        <div class="kpi-sub"><?= htmlspecialchars($sub) ?></div>
    <?php endif; ?>
</div>