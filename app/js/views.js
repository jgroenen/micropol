import { maakViews } from 'cdn/views.js';
import { verbergTooltip } from './tooltip.js';

// the views of the app (views/<id>.html), see views.js on the cdn; a tooltip closes when the view changes
export const { laadView, toonView } = maakViews('/views', verbergTooltip);
