<template>
  <k-panel-inside class="k-trash-view">
    <k-header>
      {{ $t("sigtrygg-space.kirby-trash.title") }}
      <template #buttons>
        <k-button
          v-if="total > 0 && canDelete && !issue"
          icon="trash"
          variant="filled"
          theme="negative"
          size="sm"
          dialog="trash/empty"
        >
          {{ $t("sigtrygg-space.kirby-trash.emptyTrash") }}
        </k-button>
      </template>
    </k-header>
    <!-- with an unusable root, "the trash is empty" would be a lie —
         the warning replaces the list/empty state entirely -->
    <k-box v-if="issue" theme="negative" icon="alert">
      {{ issue }}
    </k-box>
    <template v-else>
      <!-- explains why the red "cleanup required" badge led here:
           opening the area removed the expired items just now -->
      <k-box v-if="cleaned" class="k-trash-cleaned" theme="info" icon="check">
        {{ cleaned }}
      </k-box>
      <!-- entries on disk that never become rows because their
           meta.json is unreadable: the badge counts them, so the
           view has to account for them instead of pretending the
           trash holds nothing but what the table shows -->
      <k-box
        v-if="unlisted"
        class="k-trash-unlisted"
        theme="warning"
        icon="alert"
      >
        {{ unlisted }}
      </k-box>
      <!-- k-table directly instead of k-collection: only the table
           itself surfaces the `cell` click event (k-items swallows
           it), which opens the details dialog — the same single-click
           pattern as Kirby's structure tables -->
      <template v-if="items.length > 0">
        <k-table
          :columns="columns"
          :rows="rows"
          :style="tableStyle"
          @cell="onDetails"
        />
        <footer class="k-collection-footer">
          <k-text class="k-help k-collection-help">
            {{ $t("sigtrygg-space.kirby-trash.help") }}
          </k-text>
        </footer>
      </template>
      <!-- only a genuinely empty trash says so — with unlistable
           entries left, the note above explains the state instead -->
      <k-empty v-if="items.length === 0 && total === 0" icon="trash">
        {{ $t("sigtrygg-space.kirby-trash.empty") }}
      </k-empty>
    </template>
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    items: {
      type: Array,
      default: () => []
    },
    columns: {
      type: Object,
      default: () => ({})
    },
    canRestore: Boolean,
    canDelete: Boolean,
    // number of entries in the trash, including the ones whose
    // meta.json is unreadable — those never reach `items`, so
    // gating the empty-trash button on the table rows would leave
    // no way to remove them from the Panel
    total: {
      type: Number,
      default: 0
    },
    postponeLabel: String,
    issue: String,
    cleaned: String,
    // validated CSS length from the previews option, or null for
    // Kirby's standard table row height
    rowHeight: String,
    // note about entries counted by `total` that `items` cannot
    // show, or null when the table covers everything on disk
    unlisted: String
  },
  methods: {
    // fired by k-table for a click on any data cell (the options
    // column stays untouched); opening by path goes through the
    // same Panel pipeline as k-button's dialog prop
    onDetails({ row }) {
      this.$panel.dialog.open("trash/" + row.trashId + "/details");
    }
  },
  computed: {
    // the whole k-table geometry (rows, index column, buttons and
    // our image column) hangs on this one CSS variable, so scaling
    // it scales everything consistently
    tableStyle() {
      return this.rowHeight
        ? { "--table-row-height": this.rowHeight }
        : null;
    },
    // all dialogs are defined in the plugin's PHP backend and
    // opened via k-button's native `dialog` prop; submitting
    // runs through the Panel's dialog pipeline (loading
    // spinner, disabled buttons, view reload)
    rows() {
      return this.items.map((item) => ({
        ...item,
        options: [
          {
            icon: "info",
            text: this.$t("sigtrygg-space.kirby-trash.details"),
            dialog: "trash/" + item.trashId + "/details"
          },
          "-",
          {
            icon: "undo",
            text: this.$t("sigtrygg-space.kirby-trash.restore"),
            dialog: "trash/" + item.trashId + "/restore",
            disabled: !this.canRestore
          },
          // postpone shares the restore permission; hidden when
          // retention is disabled (no label) or the item has no
          // deletion date to postpone from
          ...(this.postponeLabel && item.postponable
            ? [
                {
                  icon: "clock",
                  text: this.postponeLabel,
                  dialog: "trash/" + item.trashId + "/postpone",
                  disabled: !this.canRestore
                }
              ]
            : []),
          "-",
          {
            icon: "trash",
            text: this.$t("sigtrygg-space.kirby-trash.delete"),
            dialog: "trash/" + item.trashId + "/delete",
            disabled: !this.canDelete
          }
        ]
      }));
    }
  }
};
</script>
