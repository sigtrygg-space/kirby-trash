<template>
  <k-panel-inside class="k-trash-view">
    <k-header>
      {{ $t("sigtrygg-space.kirby-trash.title") }}
      <template #buttons>
        <k-button
          v-if="items.length > 0 && canDelete && !issue"
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
      <k-collection
        v-if="items.length > 0"
        layout="table"
        :columns="columns"
        :items="rows"
        :help="$t('sigtrygg-space.kirby-trash.help')"
      />
      <k-empty v-else icon="trash">
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
    postponeLabel: String,
    issue: String
  },
  computed: {
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
