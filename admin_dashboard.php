                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script>
document.querySelectorAll('.admin-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var name = tab.dataset.tab;
        document.querySelectorAll('.admin-tab').forEach(function(item) {
            item.classList.toggle('active', item.dataset.tab === name);
        });
        document.querySelectorAll('.admin-content').forEach(function(panel) {
            panel.classList.toggle('active', panel.id === name + '-content');
        });
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState({}, '', url.toString());
    });
});
</script>
</body>
</html>
