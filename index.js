panel.plugin("sigtrygg-space/kirby-trash", {
  components: {
    "k-trash-view": {
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
        canDelete: Boolean
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
      },
      template: `
        <k-panel-inside class="k-trash-view">
          <k-header>
            {{ $t("sigtrygg-space.kirby-trash.title") }}
            <template #buttons>
              <k-button
                v-if="items.length > 0 && canDelete"
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
        </k-panel-inside>
      `
    },
    // renders the "time left" column; k-table resolves the column's
    // type "remaining" to this globally registered component
    "k-table-remaining-cell": {
      props: {
        column: Object,
        field: Object,
        row: Object,
        value: [String, Number]
      },
      template: `
        <span
          class="k-trash-remaining-cell"
          :data-theme="row.expiresSoon ? column.warnTheme : false"
        >{{ value }}</span>
      `
    },
    "k-trash-details-dialog": {
      props: {
        fields: {
          type: Array,
          default: () => []
        },
        trashId: String,
        canRestore: Boolean,
        canDelete: Boolean,
        // passed by the panel's dialog island; must be forwarded to
        // k-dialog explicitly — Vue 2 attribute fallthrough only sets
        // it as a DOM attribute, not as the k-dialog prop, and without
        // it k-dialog never teleports into the dialog portal
        visible: {
          type: Boolean,
          default: false
        }
      },
      computed: {
        submitButton() {
          return { text: this.$t("close") };
        }
      },
      template: `
        <k-dialog
          class="k-trash-details-dialog"
          :cancel-button="false"
          :submit-button="submitButton"
          :visible="visible"
          @cancel="$emit('cancel')"
          @submit="$panel.dialog.close()"
        >
          <dl>
            <div v-for="field in fields" :key="field.label">
              <dt>{{ field.label }}</dt>
              <dd>{{ field.value }}</dd>
            </div>
          </dl>
          <k-button-group
            v-if="canRestore || canDelete"
            class="k-trash-details-actions"
          >
            <k-button
              icon="undo"
              variant="filled"
              :disabled="!canRestore"
              :dialog="'trash/' + trashId + '/restore'"
            >
              {{ $t("sigtrygg-space.kirby-trash.restore") }}
            </k-button>
            <k-button
              icon="trash"
              variant="filled"
              theme="negative"
              :disabled="!canDelete"
              :dialog="'trash/' + trashId + '/delete'"
            >
              {{ $t("sigtrygg-space.kirby-trash.delete") }}
            </k-button>
          </k-button-group>
        </k-dialog>
      `
    }
  }
});
