# Font Configuration System - IRANYekanXVF

## Overview
This system provides a comprehensive font configuration for the IRANYekanXVF variable font with multiple obfuscation levels and advanced features.

## Font Features

### Core Font Features
- **Variable Font Support**: Weight range 100-900
- **Persian Numerals (ss02)**: Converts English numbers to Persian/Arabic numerals
- **Monospace Numbers (ss03)**: Makes numbers equal-width for better alignment
- **Combined Features**: Use both ss02 and ss03 together

### Persian Numerals (ss02)
The `ss02` feature automatically converts English numbers (123) to Persian numerals (۱۲۳).

```css
.ss02 {
    -moz-font-feature-settings: "ss02";
    -webkit-font-feature-settings: "ss02";
    font-feature-settings: "ss02";
}
```

**Usage Examples:**
```html
<!-- Basic Persian numerals -->
<span class="ss02">123456789</span>

<!-- In tables and lists -->
<td class="ss02">2024</td>
```

### Monospace Numbers (ss03)
The `ss03` feature makes all numbers equal-width, perfect for tables and lists.

```css
.ss03 {
    -moz-font-feature-settings: "ss03";
    -webkit-font-feature-settings: "ss03";
    font-feature-settings: "ss03";
}
```

**Usage Examples:**
```html
<!-- Monospace numbers for alignment -->
<span class="ss03">۱۲۳۴۵۶۷۸۹</span>

<!-- In price displays -->
<div class="ss03">۱۲,۵۰۰ تومان</div>
```

### Combined Features
Use both features together for Persian numerals that are also monospace.

```css
.ss02.ss03,
.persian-numbers-monospace {
    -moz-font-feature-settings: "ss02", "ss03";
    -webkit-font-feature-settings: "ss02", "ss03";
    font-feature-settings: "ss02", "ss03";
}
```

**Usage Examples:**
```html
<!-- Persian numerals + monospace -->
<span class="ss02 ss03">123456789</span>

<!-- Alternative class name -->
<span class="persian-numbers-monospace">123456789</span>
```

## Browser Support
- ✅ Chrome 60+
- ✅ Firefox 34+
- ✅ Safari 9+
- ✅ Edge 79+
- ✅ iOS Safari 9+
- ✅ Android Chrome 60+

## Implementation

### CSS Classes Available
1. **`.ss02`** - Persian numerals
2. **`.ss03`** - Monospace numbers
3. **`.ss02.ss03`** - Combined features
4. **`.persian-numbers-monospace`** - Alternative combined class

### Where to Apply
- **Numbers in Persian text**: Use `.ss02`
- **Tables and lists**: Use `.ss03` or `.ss02.ss03`
- **Prices and amounts**: Use `.ss02.ss03`
- **Dates and times**: Use `.ss02`

### Examples in Practice
```html
<!-- Dashboard statistics -->
<div class="stat-card">
    <h3>تعداد خریدها</h3>
    <div class="stat-number ss02">123</div>
</div>

<!-- Price table -->
<table>
    <tr>
        <td>قیمت</td>
        <td class="ss02 ss03">۱۲,۵۰۰ تومان</td>
    </tr>
</table>

<!-- Date display -->
<div class="date-display ss02">2024-01-15</div>
```

## Files
- `fonts-ultimate-obfuscated.css` - Main font configuration
- `includes/mobile_header.php` - Mobile font loading
- `includes/header.php` - Desktop font loading
- `test_font_features.php` - Feature demonstration page

## Testing
Visit `test_font_features.php` to see all font features in action and test Persian numerals on your device.
