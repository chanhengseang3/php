<?php
/**
 * order_form.php
 *
 * Handles step 4: display the HTML order form with pre-filled state.
 * Expects catalog arrays and state variables from index.php.
 */
?>
<form method="post" data-order-form>
    <?php
    /**
     * Implement CSRF token generation
     * Store token in the session
     * Add hidden field with token to preference and login forms
     */
    ?>
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken ?? getCsrfToken()); ?>">
    <label>
        Customer Name
        <input type="text" name="customer_name" required value="<?= h($_POST['customer_name'] ?? '') ?>">
    </label>
    <label>
        Email
        <input type="email" name="customer_email" required value="<?= h($_POST['customer_email'] ?? '') ?>">
    </label>
    <label>
        Phone
        <input type="text" name="customer_phone" value="<?= h($_POST['customer_phone'] ?? '') ?>">
    </label>
    <label>
        Pickup Note
        <textarea name="pickup_note" rows="2"><?= h($_POST['pickup_note'] ?? '') ?></textarea>
    </label>

    <label>
        Coffee
        <select name="coffee_id" required>
            <option value="">Select a coffee</option>
            <?php foreach ($coffees as $coffee): ?>
                <option value="<?= h((string) $coffee['id']); ?>" <?= ($coffee['id'] ?? null) == ($coffeeId ?? null) ? 'selected' : ''; ?>>
                    <?= h($coffee['name']); ?> — $<?= number_format((float) $coffee['base_price'], 2); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        Size
        <select name="size_id" required>
            <option value="">Select a size</option>
            <?php foreach ($sizes as $size): ?>
                <option value="<?= h((string) $size['id']); ?>" <?= ($size['id'] ?? null) == ($sizeId ?? null) ? 'selected' : ''; ?>>
                    <?= h($size['label']); ?> (<?= h($size['ounces']); ?> oz) — +$<?= number_format((float) $size['price_modifier'], 2); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        Quantity
        <input type="number" name="quantity" min="1" max="12" value="<?= h($_POST['quantity'] ?? '1'); ?>">
    </label>

    <label>
        Sweeteners (optional)
        <select name="sweeteners[]" multiple size="4">
            <?php foreach ($sweeteners as $sweetener): ?>
                <?php $selected = in_array((int) $sweetener['id'], $selectedSweetenerIds ?? [], true) ? 'selected' : ''; ?>
                <option value="<?= h((string) $sweetener['id']); ?>" <?= $selected; ?>>
                    <?= h($sweetener['name']); ?> (+$<?= number_format((float) $sweetener['additional_cost'], 2); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        Creamers (choose flavored options!)
        <select name="creamers[]" multiple size="4">
            <?php foreach ($creamers as $creamer): ?>
                <?php $selected = in_array((int) $creamer['id'], $selectedCreamerIds ?? [], true) ? 'selected' : ''; ?>
                <option value="<?= h((string) $creamer['id']); ?>" <?= $selected; ?>>
                    <?= h($creamer['name']); ?> <?= $creamer['is_flavored'] ? '(flavored)' : ''; ?> (+$<?= number_format((float) $creamer['additional_cost'], 2); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="preview" data-price-preview>Pricing will update as you pick options.</div>

    <div class="form-actions">
        <button type="submit">Add to Cart</button>
        <span class="muted" data-cart-status></span>
    </div>
</form>
