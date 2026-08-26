package com.jay.movie.ui;

import android.content.Context;
import android.util.AttributeSet;
import android.view.View;
import android.view.ViewGroup;

import java.util.ArrayList;
import java.util.List;

/** 流式布局：chips 自动换行（季/集/音轨/播放源选择用） */
public class FlowLayout extends ViewGroup {

    private final List<Integer> lineHeights = new ArrayList<>();

    public FlowLayout(Context c) {
        super(c);
    }

    public FlowLayout(Context c, AttributeSet a) {
        super(c, a);
    }

    @Override
    protected void onMeasure(int wms, int hms) {
        lineHeights.clear();
        int width = MeasureSpec.getSize(wms) - getPaddingLeft() - getPaddingRight();
        int x = getPaddingLeft(), y = getPaddingTop(), lineH = 0;

        for (int i = 0; i < getChildCount(); i++) {
            View ch = getChildAt(i);
            if (ch.getVisibility() == GONE) continue;
            LayoutParams lp = ch.getLayoutParams();
            int lm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).leftMargin : 0;
            int rm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).rightMargin : 0;
            int tm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).topMargin : 0;
            int bm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).bottomMargin : 0;

            measureChildWithMargins(ch, wms, 0, hms, 0);
            int cw = ch.getMeasuredWidth() + lm + rm;
            int chh = ch.getMeasuredHeight() + tm + bm;

            if (x > getPaddingLeft() && x + cw > width + getPaddingLeft()) {
                lineHeights.add(lineH);
                y += lineH;
                lineH = 0;
                x = getPaddingLeft();
            }
            x += cw;
            lineH = Math.max(lineH, chh);
        }
        lineHeights.add(lineH);
        int totalH = y + lineH + getPaddingBottom();
        setMeasuredDimension(MeasureSpec.getSize(wms), resolveSize(totalH, hms));
    }

    @Override
    protected void onLayout(boolean changed, int l, int t, int r, int b) {
        int width = getWidth() - getPaddingLeft() - getPaddingRight();
        int x = getPaddingLeft(), y = getPaddingTop(), lineIdx = 0, lineH = lineHeights.isEmpty() ? 0 : lineHeights.get(0);

        for (int i = 0; i < getChildCount(); i++) {
            View ch = getChildAt(i);
            if (ch.getVisibility() == GONE) continue;
            LayoutParams lp = ch.getLayoutParams();
            int lm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).leftMargin : 0;
            int rm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).rightMargin : 0;
            int tm = lp instanceof MarginLayoutParams ? ((MarginLayoutParams) lp).topMargin : 0;

            int cw = ch.getMeasuredWidth() + lm + rm;
            int chh = ch.getMeasuredHeight();

            if (x > getPaddingLeft() && x + cw > width + getPaddingLeft()) {
                lineIdx++;
                if (lineIdx < lineHeights.size()) lineH = lineHeights.get(lineIdx);
                y += lineH;
                x = getPaddingLeft();
            }
            ch.layout(x + lm, y + tm, x + lm + ch.getMeasuredWidth(), y + tm + chh);
            x += cw;
        }
    }

    @Override
    public LayoutParams generateLayoutParams(AttributeSet attrs) {
        return new MarginLayoutParams(getContext(), attrs);
    }

    @Override
    protected LayoutParams generateDefaultLayoutParams() {
        return new MarginLayoutParams(LayoutParams.WRAP_CONTENT, LayoutParams.WRAP_CONTENT);
    }

    @Override
    protected LayoutParams generateLayoutParams(LayoutParams p) {
        return new MarginLayoutParams(p);
    }

    @Override
    protected boolean checkLayoutParams(LayoutParams p) {
        return p instanceof MarginLayoutParams;
    }
}
