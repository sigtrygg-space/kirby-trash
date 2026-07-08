import DetailsDialog from "./components/DetailsDialog.vue";
import RemainingCell from "./components/RemainingCell.vue";
import TrashView from "./components/TrashView.vue";
import "./styles.css";

panel.plugin("sigtrygg-space/kirby-trash", {
  components: {
    "k-trash-view": TrashView,
    "k-table-remaining-cell": RemainingCell,
    "k-trash-details-dialog": DetailsDialog
  }
});
