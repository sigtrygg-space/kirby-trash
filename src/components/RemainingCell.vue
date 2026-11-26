<template>
  <span
    class="k-trash-remaining-cell"
    :data-theme="row.expiresSoon ? column.warnTheme : null"
    >{{ value }}</span
  >
</template>

<script>
/**
 * Renders the "time left" column; k-table resolves the column's
 * type "remaining" to this globally registered component.
 *
 * `data-theme` falls back to null, not false: Vue 3 renders a false
 * attribute value as the string "false", and the [data-theme]
 * selector would then style every cell. Only null or undefined drop
 * the attribute. (No template comment here: it would ship in the
 * build and turn the cell into a two-root fragment.)
 */
export default {
  // k-table passes every cell `disabled` and an `input` listener too;
  // without this, Vue 3 would put them on the span as a stray
  // attribute and a native listener (Kirby's own cells do the same)
  inheritAttrs: false,
  props: {
    column: Object,
    field: Object,
    row: Object,
    value: [String, Number]
  }
};
</script>
