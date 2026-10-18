<div class="mb-3">
    <label class="form-label" for="code">6-digit code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="\d{3}\s?\d{3}"
           class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror" placeholder="123456" required>
    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
