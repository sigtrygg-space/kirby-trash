panel.plugin("sigtrygg-space/kirby-trash", {
  components: {
    "k-trash-view": {
      props: {
        items: {
          type: Array,
          default: () => []
        },
        canRestore: Boolean,
        canDelete: Boolean,
        totalSize: String
      },
      computed: {
        collectionItems() {
          return this.items.map((item) => ({
            ...item,
            link: false,
            options: [
              {
                icon: "undo",
                text: this.$t("sigtrygg-space.kirby-trash.restore"),
                option: "restore",
                disabled: !this.canRestore
              },
              "-",
              {
                icon: "trash",
                text: this.$t("sigtrygg-space.kirby-trash.delete"),
                option: "delete",
                disabled: !this.canDelete
              }
            ]
          }));
        }
      },
      methods: {
        onOption(option, item) {
          if (option === "restore") {
            return this.restore(item);
          }

          if (option === "delete") {
            return this.remove(item);
          }
        },
        restore(item) {
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text: this.$t("sigtrygg-space.kirby-trash.dialog.restore", {
                title: item.text
              }),
              submitButton: {
                icon: "undo",
                text: this.$t("sigtrygg-space.kirby-trash.restore")
              }
            },
            on: {
              submit: () =>
                this.request("post", "trash/" + item.trashId + "/restore", "restored")
            }
          });
        },
        remove(item) {
          this.$panel.dialog.open({
            component: "k-remove-dialog",
            props: {
              text: this.$t("sigtrygg-space.kirby-trash.dialog.delete", {
                title: item.text
              })
            },
            on: {
              submit: () => this.request("delete", "trash/" + item.trashId, "deleted")
            }
          });
        },
        emptyTrash() {
          this.$panel.dialog.open({
            component: "k-remove-dialog",
            props: {
              text: this.$t("sigtrygg-space.kirby-trash.dialog.empty", {
                count: this.items.length,
                size: this.totalSize
              }),
              submitButton: {
                icon: "trash",
                text: this.$t("sigtrygg-space.kirby-trash.emptyTrash")
              }
            },
            on: {
              submit: () => this.request("delete", "trash", "emptied")
            }
          });
        },
        async request(method, path, successKey) {
          try {
            await this.$api[method](path);
            this.$panel.dialog.close();
            this.$panel.notification.success(
              this.$t("sigtrygg-space.kirby-trash.notification." + successKey)
            );
            this.$panel.view.reload();
          } catch (error) {
            this.$panel.error(error);
          }
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
                @click="emptyTrash"
              >
                {{ $t("sigtrygg-space.kirby-trash.emptyTrash") }}
              </k-button>
            </template>
          </k-header>
          <k-collection
            v-if="items.length > 0"
            :items="collectionItems"
            @option="onOption"
          />
          <k-empty v-else icon="trash">
            {{ $t("sigtrygg-space.kirby-trash.empty") }}
          </k-empty>
        </k-panel-inside>
      `
    }
  }
});
