<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BFSG Accessibility Report - {{ $url }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
        }

        header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .meta {
            display: flex;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-size: 18px;
            font-weight: 600;
            margin-top: 5px;
        }

        .score-section {
            background: #f8f9fa;
            padding: 30px 40px;
            border-bottom: 1px solid #e0e0e0;
        }

        .score-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .score-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #e0e0e0;
        }

        .score-card.primary {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .score-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .score-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.7;
        }

        .grade {
            font-size: 48px;
            font-weight: bold;
        }

        .content {
            padding: 40px;
        }

        .summary {
            margin-bottom: 40px;
        }

        .summary h2 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }

        .summary-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
        }

        .summary-count {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .summary-count.critical { color: #dc3545; }
        .summary-count.error { color: #fd7e14; }
        .summary-count.warning { color: #ffc107; }
        .summary-count.notice { color: #17a2b8; }

        .category {
            margin-bottom: 40px;
        }

        .category-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-left: 4px solid #667eea;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .category-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
        }

        .category-count {
            color: #666;
            font-size: 14px;
            margin-left: 10px;
        }

        .issue {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 15px;
            transition: box-shadow 0.3s;
        }

        .issue:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .issue-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 10px;
        }

        .severity-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        .severity-badge.critical {
            background: #dc3545;
            color: white;
        }

        .severity-badge.error {
            background: #fd7e14;
            color: white;
        }

        .severity-badge.warning {
            background: #ffc107;
            color: #000;
        }

        .severity-badge.notice {
            background: #17a2b8;
            color: white;
        }

        .issue-content {
            flex: 1;
        }

        .rule-tag {
            display: inline-block;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }

        .issue-message {
            font-size: 16px;
            color: #333;
            margin-bottom: 8px;
        }

        .issue-suggestion {
            background: #e7f3ff;
            border-left: 3px solid #667eea;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 4px;
        }

        .suggestion-icon {
            color: #667eea;
            margin-right: 5px;
        }

        .no-issues {
            text-align: center;
            padding: 60px 20px;
        }

        .success-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-issues h3 {
            color: #28a745;
            font-size: 24px;
            margin-bottom: 10px;
        }

        footer {
            background: #f8f9fa;
            padding: 20px 40px;
            text-align: center;
            color: #666;
            font-size: 12px;
            border-top: 1px solid #e0e0e0;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .container {
                box-shadow: none;
            }

            .issue {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>BFSG Accessibility Report</h1>
            <p>Comprehensive accessibility analysis based on WCAG 2.1 & BFSG requirements</p>

            <div class="meta">
                <div class="meta-item">
                    <span class="meta-label">URL</span>
                    <span class="meta-value">{{ $url }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Generated</span>
                    <span class="meta-value">{{ $timestamp->format('Y-m-d H:i:s') }}</span>
                </div>
            </div>
        </header>

        <div class="score-section">
            <div class="score-grid">
                <div class="score-card primary">
                    <div class="grade">{{ $stats['grade'] }}</div>
                    <div class="score-label">Grade</div>
                </div>
                <div class="score-card primary">
                    <div class="score-number">{{ $stats['compliance_score'] }}%</div>
                    <div class="score-label">Compliance Score</div>
                </div>
                <div class="score-card">
                    <div class="score-number">{{ $stats['total_issues'] }}</div>
                    <div class="score-label">Total Issues</div>
                </div>
            </div>
        </div>

        <div class="content">
            @if($stats['total_issues'] === 0)
                <div class="no-issues">
                    <div class="success-icon">✅</div>
                    <h3>No Accessibility Issues Found!</h3>
                    <p>This page meets WCAG 2.1 Level AA requirements.</p>
                </div>
            @else
                <div class="summary">
                    <h2>Issues by Severity</h2>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-count critical">{{ $stats['critical'] }}</div>
                            <div class="score-label">Critical</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-count error">{{ $stats['errors'] }}</div>
                            <div class="score-label">Errors</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-count warning">{{ $stats['warnings'] }}</div>
                            <div class="score-label">Warnings</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-count notice">{{ $stats['notices'] }}</div>
                            <div class="score-label">Notices</div>
                        </div>
                    </div>
                </div>

                @foreach($violations as $category => $issues)
                    <div class="category">
                        <div class="category-header">
                            <span class="category-title">{{ ucfirst($category) }}</span>
                            <span class="category-count">{{ count($issues) }} {{ count($issues) === 1 ? 'issue' : 'issues' }}</span>
                        </div>

                        @foreach($issues as $issue)
                            <div class="issue">
                                <div class="issue-header">
                                    <span class="severity-badge {{ $issue['severity'] ?? 'notice' }}">
                                        {{ $issue['severity'] ?? 'notice' }}
                                    </span>
                                    <div class="issue-content">
                                        <div class="rule-tag">{{ $issue['rule'] ?? 'BFSG' }}</div>
                                        <div class="issue-message">{{ $issue['message'] }}</div>
                                        @if(isset($issue['element']))
                                            <div style="font-size: 13px; color: #666; margin-top: 5px;">
                                                Element: <code style="background: #f1f1f1; padding: 2px 6px; border-radius: 3px;">{{ $issue['element'] }}</code>
                                            </div>
                                        @endif
                                        @if(isset($issue['suggestion']))
                                            <div class="issue-suggestion">
                                                <span class="suggestion-icon">💡</span>
                                                <strong>Suggestion:</strong> {{ $issue['suggestion'] }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>

        <footer>
            Generated by <strong>Laravel BFSG</strong> v1.5.0 | <a href="https://github.com/itsjustvita/laravel-bfsg" style="color: #667eea;">github.com/itsjustvita/laravel-bfsg</a>
        </footer>
    </div>
</body>
</html>
