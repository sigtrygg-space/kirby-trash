<template>
  <span
    class="k-trash-remaining-cell"
    :data-theme="row.expiresSoon ? column.warnTheme : false"
    >{{ value }}</span
  >
</template>

<script>
/**
 * Renders the "time left" column; k-table resolves the column's
 * type "remaining" to this globally registered component.
 */
export default {
  props: {
    column: Object,
    field: Object,
    row: Object,
    value: [String, Number]
  }
};
</script>
